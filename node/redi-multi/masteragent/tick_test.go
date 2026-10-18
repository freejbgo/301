package masteragent

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
)

func TestTickPullsOnlyChangedSplitRevision(t *testing.T) {
	const (
		challengesRev = "challenges-rev-a"
		rulesRevA     = "rules-rev-a"
		rulesRevB     = "rules-rev-b"
		certsRev      = "certs-rev-a"
	)

	var mu sync.Mutex
	rulesPulls := 0
	certPulls := 0
	challengePulls := 0
	revisionCalls := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		switch r.Method + " " + r.URL.Path {
		case "POST /api/nodes/heartbeat":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true}`))
		case "GET /api/nodes/revision":
			revisionCalls++
			rules := rulesRevA
			if revisionCalls > 1 {
				rules = rulesRevB
			}
			_ = json.NewEncoder(w).Encode(map[string]string{
				"revision":              "combined",
				"challenges_revision":   challengesRev,
				"rules_revision":        rules,
				"certificates_revision": certsRev,
			})
		case "GET /api/nodes/redirect-rules":
			rulesPulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"rules": []interface{}{}})
		case "GET /api/nodes/acme-challenges":
			challengePulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"challenges": []interface{}{}})
		case "GET /api/nodes/certificates":
			certPulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"certificates": []interface{}{}})
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	cfg := config{MasterURL: srv.URL, Token: "1.testtoken"}
	client := srv.Client()
	registered := true
	revs := revisionState{
		challenges:   challengesRev,
		rules:        rulesRevA,
		certificates: certsRev,
	}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("first tick: %v", err)
	}
	if rulesPulls != 0 || certPulls != 0 || challengePulls != 0 {
		t.Fatalf("expected no pulls when revisions unchanged, got rules=%d challenges=%d certs=%d", rulesPulls, challengePulls, certPulls)
	}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("second tick: %v", err)
	}
	if rulesPulls != 1 {
		t.Fatalf("expected single redirect-rules pull, got %d", rulesPulls)
	}
	if certPulls != 0 || challengePulls != 0 {
		t.Fatalf("expected no cert/challenge pulls, got challenges=%d certs=%d", challengePulls, certPulls)
	}
	if revs.rules != rulesRevB {
		t.Fatalf("expected rules revision updated to %q, got %q", rulesRevB, revs.rules)
	}
}
