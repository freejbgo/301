package masteragent

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"redi-multi/acme"
	"redi-multi/certs"
	"redi-multi/rules"
	"time"

	"github.com/sirupsen/logrus"
)

const agentVersion = "0.1.1"

type config struct {
	MasterURL string
	Token     string
}

func Run() {
	master := os.Getenv("REDI_MASTER_URL")
	token := os.Getenv("REDI_NODE_TOKEN")
	if master == "" || token == "" {
		logrus.Warn("[masteragent] REDI_MASTER_URL or REDI_NODE_TOKEN not set; agent disabled")
		return
	}
	cfg := config{MasterURL: stringsTrimRightSlash(master), Token: token}
	go loop(cfg)
}

func loop(cfg config) {
	client := &http.Client{Timeout: 30 * time.Second}
	registered := false
	revision := ""
	for {
		if err := tick(client, cfg, &registered, &revision); err != nil {
			logrus.Errorf("[masteragent] %v", err)
		}
		time.Sleep(5 * time.Second)
	}
}

func tick(client *http.Client, cfg config, registered *bool, revision *string) error {
	if !*registered {
		if err := postJSON(client, cfg, "/api/nodes/register", map[string]string{"version": agentVersion}, nil, registered); err != nil {
			return err
		}
		*registered = true
	}
	var rev struct {
		Revision string `json:"revision"`
	}
	if err := getJSON(client, cfg, "/api/nodes/revision", &rev, registered); err != nil {
		return err
	}
	if rev.Revision == "" || rev.Revision != *revision {
		if err := pullChallenges(client, cfg, registered); err != nil {
			return err
		}
		if err := pullRedirectRules(client, cfg, registered); err != nil {
			return err
		}
		if err := pullCertificates(client, cfg, registered); err != nil {
			return err
		}
		*revision = rev.Revision
	}
	if err := postJSON(client, cfg, "/api/nodes/heartbeat", map[string]string{"version": agentVersion}, nil, registered); err != nil {
		return err
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
	items := make([]acme.Item, len(out.Challenges))
	domains := make([]string, 0, len(out.Challenges))
	for i, c := range out.Challenges {
		items[i] = acme.Item{
			DomainName:   c.DomainName,
			Token:        c.Token,
			ResponseBody: c.ResponseBody,
		}
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
	failed := false
	list := make([]certs.Entry, 0, len(out.Certificates))
	for _, row := range out.Certificates {
		entry := certs.Entry{
			CertID:      row.CertID,
			Domain:      row.Domain,
			Certificate: []byte(row.Certificate),
			PrivateKey:  []byte(row.PrivateKey),
			ForceHTTPS:  bool(row.ForceHTTPS),
		}
		if err := certs.InstallEntry(entry); err != nil {
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
		if ackErr := postJSON(client, cfg, "/api/nodes/ack", map[string]interface{}{
			"cert_id": row.CertID,
			"ok":      true,
		}, nil, registered); ackErr != nil {
			return ackErr
		}
	}
	certs.ReplaceAll(list)
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
	if res.StatusCode == http.StatusUnauthorized || res.StatusCode == http.StatusForbidden {
		if registered != nil {
			*registered = false
		}
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(res.Body)
		return fmt.Errorf("GET %s: %s", path, string(b))
	}
	return json.NewDecoder(res.Body).Decode(dest)
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
	if res.StatusCode == http.StatusUnauthorized || res.StatusCode == http.StatusForbidden {
		if registered != nil {
			*registered = false
		}
	}
	if res.StatusCode >= 300 {
		b, _ := io.ReadAll(res.Body)
		return fmt.Errorf("POST %s: %s", path, string(b))
	}
	if dest != nil {
		return json.NewDecoder(res.Body).Decode(dest)
	}
	return nil
}

func stringsTrimRightSlash(s string) string {
	for len(s) > 0 && s[len(s)-1] == '/' {
		s = s[:len(s)-1]
	}
	return s
}
