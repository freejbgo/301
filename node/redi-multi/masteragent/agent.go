package masteragent

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"redi-multi/acme"
	"redi-multi/certs"
	"redi-multi/rules"
	"strings"
	"time"

	"github.com/sirupsen/logrus"
)

const agentVersion = "0.1.3"

type config struct {
	MasterURL string
	Token     string
}

const maxMasterJSONBytes = 32 << 20

func Run() {
	master := os.Getenv("REDI_MASTER_URL")
	token := os.Getenv("REDI_NODE_TOKEN")
	if master == "" || token == "" {
		logrus.Warn("[masteragent] REDI_MASTER_URL or REDI_NODE_TOKEN not set; agent disabled")
		return
	}
	insecure := strings.TrimSpace(os.Getenv("REDI_MASTER_INSECURE")) == "1"
	if strings.HasPrefix(strings.ToLower(strings.TrimSpace(master)), "http://") {
		if !insecure {
			logrus.Fatal("[masteragent] REDI_MASTER_URL must use https:// (or set REDI_MASTER_INSECURE=1 for lab only)")
		}
		logrus.Warn("[masteragent] REDI_MASTER_URL uses http://; token and private keys are not protected")
	}
	cfg := config{MasterURL: stringsTrimRightSlash(master), Token: token}
	go loop(cfg)
}

type revisionState struct {
	legacy       string
	challenges   string
	rules        string
	certificates string
}

func loop(cfg config) {
	client := &http.Client{Timeout: 30 * time.Second}
	registered := false
	revs := revisionState{}
	for {
		if err := tick(client, cfg, &registered, &revs); err != nil {
			logrus.Errorf("[masteragent] %v", err)
		}
		time.Sleep(5 * time.Second)
	}
}

func tick(client *http.Client, cfg config, registered *bool, revs *revisionState) error {
	if !*registered {
		if err := postJSON(client, cfg, "/api/nodes/register", map[string]string{"version": agentVersion}, nil, registered); err != nil {
			return err
		}
		*registered = true
	}
	var rev struct {
		Revision             string `json:"revision"`
		ChallengesRevision   string `json:"challenges_revision"`
		RulesRevision        string `json:"rules_revision"`
		CertificatesRevision string `json:"certificates_revision"`
	}
	if err := postJSON(client, cfg, "/api/nodes/heartbeat", map[string]string{"version": agentVersion}, nil, registered); err != nil {
		return err
	}
	if err := getJSON(client, cfg, "/api/nodes/revision", &rev, registered); err != nil {
		return err
	}
	split := rev.ChallengesRevision != "" || rev.RulesRevision != "" || rev.CertificatesRevision != ""
	if split {
		if rev.ChallengesRevision != "" && rev.ChallengesRevision != revs.challenges {
			if err := pullChallenges(client, cfg, registered); err != nil {
				logrus.Warnf("[masteragent] pull challenges: %v", err)
			} else {
				revs.challenges = rev.ChallengesRevision
			}
		}
		if rev.RulesRevision != "" && rev.RulesRevision != revs.rules {
			if err := pullRedirectRules(client, cfg, registered); err != nil {
				logrus.Warnf("[masteragent] pull redirect rules: %v", err)
			} else {
				revs.rules = rev.RulesRevision
			}
		}
		if rev.CertificatesRevision != "" && rev.CertificatesRevision != revs.certificates {
			if err := pullCertificates(client, cfg, registered); err != nil {
				logrus.Warnf("[masteragent] pull certificates: %v", err)
			} else {
				revs.certificates = rev.CertificatesRevision
			}
		}
		if rev.Revision != "" {
			revs.legacy = rev.Revision
		}
	} else if rev.Revision != "" && rev.Revision != revs.legacy {
		var pullErr error
		if err := pullChallenges(client, cfg, registered); err != nil {
			pullErr = err
			logrus.Warnf("[masteragent] pull challenges: %v", err)
		}
		if err := pullRedirectRules(client, cfg, registered); err != nil {
			pullErr = err
			logrus.Warnf("[masteragent] pull redirect rules: %v", err)
		}
		if err := pullCertificates(client, cfg, registered); err != nil {
			pullErr = err
			logrus.Warnf("[masteragent] pull certificates: %v", err)
		}
		if pullErr == nil {
			revs.legacy = rev.Revision
		}
	}
	return nil
}

func pullChallenges(client *http.Client, cfg config, registered *bool) error {
	var out struct {
		Challenges []struct {
			DomainName   string `json:"domain_name"`
			Token        string `json:"token"`
			ResponseBody string `json:"response_body"`
		} `json:"challenges"`
	}
	if err := getJSON(client, cfg, "/api/nodes/acme-challenges", &out, registered); err != nil {
		return err
	}
	items := make([]acme.Item, 0, len(out.Challenges))
	domains := make([]string, 0, len(out.Challenges))
	for _, c := range out.Challenges {
		if strings.TrimSpace(c.Token) == "" || strings.TrimSpace(c.ResponseBody) == "" {
			continue
		}
		items = append(items, acme.Item{
			DomainName:   c.DomainName,
			Token:        c.Token,
			ResponseBody: c.ResponseBody,
		})
		domains = append(domains, c.DomainName)
	}
	acme.ReplaceAll(items)
	if len(domains) > 0 {
		if err := postJSON(client, cfg, "/api/nodes/challenges-synced", map[string]interface{}{
			"domains": domains,
		}, nil, registered); err != nil {
			return err
		}
	}
	return nil
}

func pullRedirectRules(client *http.Client, cfg config, registered *bool) error {
	var out struct {
		Rules []struct {
			OriginalURL string   `json:"original_url"`
			ForceHTTPS  flexBool `json:"force_https"`
		} `json:"rules"`
	}
	if err := getJSON(client, cfg, "/api/nodes/redirect-rules", &out, registered); err != nil {
		return err
	}
	m := map[string]bool{}
	for _, r := range out.Rules {
		m[r.OriginalURL] = bool(r.ForceHTTPS)
	}
	rules.ReplaceForceHTTPS(m)
	return nil
}

func pullCertificates(client *http.Client, cfg config, registered *bool) error {
	var out struct {
		Certificates []struct {
			CertID      int      `json:"cert_id"`
			Domain      string   `json:"domain"`
			Certificate string   `json:"certificate"`
			PrivateKey  string   `json:"private_key"`
			ForceHTTPS  flexBool `json:"force_https"`
		} `json:"certificates"`
	}
	if err := getJSON(client, cfg, "/api/nodes/certificates", &out, registered); err != nil {
		return err
	}
	if len(out.Certificates) == 0 {
		certs.ReplaceAll(nil)
		return nil
	}
	list := make([]certs.Entry, 0, len(out.Certificates))
	remoteIDs := make([]int, 0, len(out.Certificates))
	failed := false
	for _, row := range out.Certificates {
		remoteIDs = append(remoteIDs, row.CertID)
		entry := certs.Entry{
			CertID:      row.CertID,
			Domain:      row.Domain,
			Certificate: []byte(row.Certificate),
			PrivateKey:  []byte(row.PrivateKey),
			ForceHTTPS:  bool(row.ForceHTTPS),
		}
		if _, err := tls.X509KeyPair(entry.Certificate, entry.PrivateKey); err != nil {
			failed = true
			if ackErr := postJSON(client, cfg, "/api/nodes/ack", map[string]interface{}{
				"cert_id": row.CertID,
				"ok":      false,
				"error":   err.Error(),
			}, nil, registered); ackErr != nil {
				return ackErr
			}
			continue
		}
		list = append(list, entry)
	}
	if len(list) == 0 {
		return fmt.Errorf("all certificates failed PEM validation; keeping previous in-memory certs")
	}
	if failed {
		certs.SyncFromMaster(list, remoteIDs)
	} else {
		certs.ReplaceAll(list)
	}
	for _, entry := range list {
		if ackErr := postJSON(client, cfg, "/api/nodes/ack", map[string]interface{}{
			"cert_id": entry.CertID,
			"ok":      true,
		}, nil, registered); ackErr != nil {
			return ackErr
		}
	}
	if failed {
		return fmt.Errorf("one or more certificates failed to install")
	}
	return nil
}

func getJSON(client *http.Client, cfg config, path string, dest interface{}, registered *bool) error {
	req, err := http.NewRequest(http.MethodGet, cfg.MasterURL+path, nil)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+cfg.Token)
	res, err := client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if shouldClearRegistration(path, res.StatusCode) {
		if registered != nil {
			*registered = false
		}
	}
	if res.StatusCode == http.StatusUpgradeRequired {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		logrus.Warnf("主控要求升级 redi-multi Agent (426): %s", string(b))
		return fmt.Errorf("GET %s: agent upgrade required (426)", path)
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		return fmt.Errorf("GET %s: %s", path, string(b))
	}
	return json.NewDecoder(io.LimitReader(res.Body, maxMasterJSONBytes)).Decode(dest)
}

func postJSON(client *http.Client, cfg config, path string, body interface{}, dest interface{}, registered *bool) error {
	buf, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequest(http.MethodPost, cfg.MasterURL+path, bytes.NewReader(buf))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+cfg.Token)
	req.Header.Set("Content-Type", "application/json")
	res, err := client.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if shouldClearRegistration(path, res.StatusCode) {
		if registered != nil {
			*registered = false
		}
	}
	if res.StatusCode == http.StatusUpgradeRequired {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		logrus.Warnf("主控要求升级 redi-multi Agent (426): %s", string(b))
		return fmt.Errorf("POST %s: agent upgrade required (426)", path)
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		return fmt.Errorf("POST %s: %s", path, string(b))
	}
	if dest != nil {
		return json.NewDecoder(io.LimitReader(res.Body, maxMasterJSONBytes)).Decode(dest)
	}
	return nil
}

// 仅 401 视为 Token 失效；ack 等业务 403/409 不应触发重新 register。
func shouldClearRegistration(path string, status int) bool {
	if status == http.StatusUnauthorized {
		return true
	}
	if status == http.StatusForbidden && !strings.HasSuffix(path, "/ack") {
		return true
	}

	return false
}

func stringsTrimRightSlash(s string) string {
	for len(s) > 0 && s[len(s)-1] == '/' {
		s = s[:len(s)-1]
	}
	return s
}
