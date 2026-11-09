package masteragent

import (
	"bytes"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"redi-multi/acme"
	"redi-multi/certs"
	"redi-multi/rules"
	"redi-multi/util/host"
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
	master = strings.TrimSpace(master)
	token = strings.TrimSpace(token)
	if host.ContainsControlChars(master) || host.ContainsControlChars(token) {
		logrus.Fatal("[masteragent] REDI_MASTER_URL or REDI_NODE_TOKEN contains invalid control characters")
	}
	insecure := strings.TrimSpace(os.Getenv("REDI_MASTER_INSECURE")) == "1"
	if strings.HasPrefix(strings.ToLower(master), "http://") {
		if !insecure {
			logrus.Fatal("[masteragent] REDI_MASTER_URL must use https:// (or set REDI_MASTER_INSECURE=1 for lab only)")
		}
		logrus.Warn("[masteragent] REDI_MASTER_URL uses http://; token and private keys are not protected")
	}
	cfg := config{MasterURL: stringsTrimRightSlash(master), Token: token}
	go loop(cfg)
}

type revisionState struct {
	combinedRevision string
	challenges   string
	rules        string
	certificates string
}

func loop(cfg config) {
	client := &http.Client{Timeout: 30 * time.Second}
	registered := false
	revs := revisionState{}
	sleep := basePollInterval
	var lastLogMsg string
	var lastLogAt time.Time
	for {
		err := tick(client, cfg, &registered, &revs)
		if err != nil {
			msg := err.Error()
			shouldLog := msg != lastLogMsg || time.Since(lastLogAt) >= time.Minute
			if shouldLog {
				if isAgentUpgradeRequired(err) {
					logrus.Errorf("[masteragent] %v — 请升级节点 redi-multi（当前 %s），或请主控管理员调整 REDI_MIN_AGENT_VERSION", err, agentVersion)
				} else {
					logrus.Errorf("[masteragent] %v", err)
				}
				lastLogMsg = msg
				lastLogAt = time.Now()
			}
			sleep = nextPollIntervalAfterError(sleep, err)
		} else {
			sleep = basePollInterval
			lastLogMsg = ""
		}
		time.Sleep(sleep)
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
				if errors.Is(err, errEmptyListDebounce) {
					revs.challenges = rev.ChallengesRevision
				}
				warnThrottled("pull-challenges", "[masteragent] pull challenges: %v", err)
			} else {
				revs.challenges = rev.ChallengesRevision
			}
		}
		if rev.RulesRevision != "" && rev.RulesRevision != revs.rules {
			if err := pullRedirectRules(client, cfg, registered); err != nil {
				if errors.Is(err, errEmptyListDebounce) {
					revs.rules = rev.RulesRevision
				}
				warnThrottled("pull-rules", "[masteragent] pull redirect rules: %v", err)
			} else {
				revs.rules = rev.RulesRevision
			}
		}
		if rev.CertificatesRevision != "" && rev.CertificatesRevision != revs.certificates {
			if err := pullCertificates(client, cfg, registered); err != nil {
				if errors.Is(err, errEmptyListDebounce) {
					revs.certificates = rev.CertificatesRevision
				}
				warnThrottled("pull-certificates", "[masteragent] pull certificates: %v", err)
			} else {
				revs.certificates = rev.CertificatesRevision
			}
		}
		if rev.Revision != "" {
			revs.combinedRevision = rev.Revision
		}
	} else if rev.Revision != "" && rev.Revision != revs.combinedRevision {
		var pullErr error
		if err := pullChallenges(client, cfg, registered); err != nil {
			pullErr = err
			warnThrottled("pull-challenges", "[masteragent] pull challenges: %v", err)
		}
		if err := pullRedirectRules(client, cfg, registered); err != nil {
			pullErr = err
			warnThrottled("pull-rules", "[masteragent] pull redirect rules: %v", err)
		}
		if err := pullCertificates(client, cfg, registered); err != nil {
			pullErr = err
			warnThrottled("pull-certificates", "[masteragent] pull certificates: %v", err)
		}
		if pullErr == nil {
			revs.combinedRevision = rev.Revision
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
	if len(out.Challenges) == 0 {
		if err := noteEmptyChallengeList(); err != nil {
			return err
		}
		acme.ReplaceAll(nil)
		return nil
	}
	items := make([]acme.Item, 0, len(out.Challenges))
	domains := make([]string, 0, len(out.Challenges))
	for _, c := range out.Challenges {
		if host.ContainsControlChars(c.DomainName) || host.ContainsControlChars(c.Token) || host.ContainsControlChars(c.ResponseBody) {
			continue
		}
		domain := host.AcmeHostKey(c.DomainName)
		if domain == "" {
			continue
		}
		if !acme.Http01TokenValid(c.Token) || !acme.Http01ResponseBodyValid(c.ResponseBody) {
			continue
		}
		token := strings.TrimSpace(c.Token)
		resp := strings.TrimSpace(c.ResponseBody)
		items = append(items, acme.Item{
			DomainName:   domain,
			Token:        token,
			ResponseBody: resp,
		})
		domains = append(domains, domain)
	}
	if len(items) == 0 {
		return fmt.Errorf("master returned %d acme-challenges but none passed local validation", len(out.Challenges))
	}
	resetEmptyChallengeStreak()
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
	if len(out.Rules) == 0 {
		if err := noteEmptyRedirectRulesList(); err != nil {
			return err
		}
		rules.ReplaceForceHTTPS(map[string]bool{})
		return nil
	}
	m := map[string]bool{}
	for _, r := range out.Rules {
		if host.ContainsControlChars(r.OriginalURL) {
			continue
		}
		label := host.AcmeHostKey(r.OriginalURL)
		if label == "" {
			continue
		}
		m[label] = bool(r.ForceHTTPS)
	}
	resetEmptyRedirectRulesStreak()
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
		if err := noteEmptyCertificateList(); err != nil {
			return err
		}
		// Master returned no pullable assigned certs; drop in-memory TLS material (see NodeAgentController certificates).
		certs.ReplaceAll(nil)
		return nil
	}
	resetEmptyCertificateStreak()
	list := make([]certs.Entry, 0, len(out.Certificates))
	remoteIDs := make([]int, 0, len(out.Certificates))
	failed := false
	for _, row := range out.Certificates {
		if host.ContainsControlChars(row.Domain) {
			continue
		}
		domain := host.AcmeHostKey(row.Domain)
		if domain == "" {
			continue
		}
		remoteIDs = append(remoteIDs, row.CertID)
		entry := certs.Entry{
			CertID:      row.CertID,
			Domain:      domain,
			Certificate: []byte(row.Certificate),
			PrivateKey:  []byte(row.PrivateKey),
			ForceHTTPS:  bool(row.ForceHTTPS),
		}
		if _, err := tls.X509KeyPair(entry.Certificate, entry.PrivateKey); err != nil {
			failed = true
			if ackErr := postJSON(client, cfg, "/api/nodes/ack", map[string]interface{}{
				"cert_id": row.CertID,
				"ok":      false,
				"error":   sanitizeAckError(err.Error()),
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
	setMasterAgentHeaders(req)
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
		logrus.Warnf("主控要求升级 redi-multi Agent (426)，当前 %s: %s", agentVersion, masterBodySnippet(b))
		return fmt.Errorf("GET %s: agent upgrade required (426)", path)
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		return fmt.Errorf("GET %s: %s", path, masterBodySnippet(b))
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
	setMasterAgentHeaders(req)
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
		logrus.Warnf("主控要求升级 redi-multi Agent (426)，当前 %s: %s", agentVersion, masterBodySnippet(b))
		return fmt.Errorf("POST %s: agent upgrade required (426)", path)
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(res.Body, maxMasterJSONBytes))
		return fmt.Errorf("POST %s: %s", path, masterBodySnippet(b))
	}
	if dest != nil {
		return json.NewDecoder(io.LimitReader(res.Body, maxMasterJSONBytes)).Decode(dest)
	}
	return nil
}

func masterBodySnippet(b []byte) string {
	return host.LogSnippet(string(b), 512)
}

func setMasterAgentHeaders(req *http.Request) {
	req.Header.Set("User-Agent", "redi-multi/"+agentVersion)
}

// 仅 401 视为 Token 失效；业务 403/409（含 ack）不应触发重新 register。
func shouldClearRegistration(path string, status int) bool {
	return status == http.StatusUnauthorized
}

func stringsTrimRightSlash(s string) string {
	for len(s) > 0 && s[len(s)-1] == '/' {
		s = s[:len(s)-1]
	}
	return s
}
