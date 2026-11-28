package masteragent

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"

	redicfg "redi-multi/config"
	"redi-multi/lagran"
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
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"rules": []map[string]interface{}{
					{"original_url": "example.test", "force_https": false},
				},
			})
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

func TestTickCombinedRevisionOnly(t *testing.T) {
	const combinedRev = "combined-rev-only"
	certPEM, keyPEM := testCertKeyPEM(t)

	var mu sync.Mutex
	rulesPulls := 0
	certPulls := 0
	challengePulls := 0

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		switch r.Method + " " + r.URL.Path {
		case "POST /api/nodes/heartbeat":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true}`))
		case "POST /api/nodes/challenges-synced", "POST /api/nodes/ack":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true}`))
		case "GET /api/nodes/revision":
			_ = json.NewEncoder(w).Encode(map[string]string{"revision": combinedRev})
		case "GET /api/nodes/redirect-rules":
			rulesPulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"rules": []map[string]interface{}{
					{"original_url": "legacy.example", "force_https": false},
				},
			})
		case "GET /api/nodes/acme-challenges":
			challengePulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"challenges": []map[string]interface{}{
					{"domain_name": "legacy.example", "token": "t", "response_body": "b"},
				},
			})
		case "GET /api/nodes/certificates":
			certPulls++
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"certificates": []map[string]interface{}{
					{
						"cert_id":     1,
						"domain":      "legacy.example",
						"certificate": certPEM,
						"private_key": keyPEM,
						"force_https": false,
					},
				},
			})
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	cfg := config{MasterURL: srv.URL, Token: "1.testtoken"}
	client := srv.Client()
	registered := true
	revs := revisionState{}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("first tick: %v", err)
	}
	if rulesPulls != 1 || challengePulls != 1 || certPulls != 1 {
		t.Fatalf("expected combined revision to pull all feeds once, got rules=%d challenges=%d certs=%d", rulesPulls, challengePulls, certPulls)
	}
	if revs.combinedRevision != combinedRev {
		t.Fatalf("expected combined revision %q, got %q", combinedRev, revs.combinedRevision)
	}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("second tick: %v", err)
	}
	if rulesPulls != 1 || challengePulls != 1 || certPulls != 1 {
		t.Fatalf("expected no second pulls on unchanged combined revision, got rules=%d challenges=%d certs=%d", rulesPulls, challengePulls, certPulls)
	}
}

func TestTickPullsLagranWhenRevisionChanges(t *testing.T) {
	prev := redicfg.Lagran
	t.Cleanup(func() {
		redicfg.Lagran = prev
		lagran.SetReloadRuntimeForTest(nil)
	})
	redicfg.Lagran = redicfg.DefaultLagran()
	redicfg.Lagran["window"] = map[string]uint16{"80": 1, "443": 1, "8080": 9}
	reloads := 0
	lagran.SetReloadRuntimeForTest(func() error {
		reloads++
		return nil
	})

	var mu sync.Mutex
	mode := "first"
	pulls := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		switch r.Method + " " + r.URL.Path {
		case "POST /api/nodes/heartbeat":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true}`))
		case "GET /api/nodes/revision":
			_ = json.NewEncoder(w).Encode(map[string]string{
				"revision":              "combined",
				"challenges_revision":   "c",
				"rules_revision":        "r",
				"certificates_revision": "e",
				"lagran_revision":       mode,
			})
		case "GET /api/nodes/lagran":
			pulls++
			var body string
			switch mode {
			case "bad":
				body = `{"lagran":{"enabled":true,"syn_ack":true,"ack":true,"window_scale":99,"confusion_times":0,"split_number":7,"window_after_split":28960,"window_confusion_last":65535,"window":{"80":1,"443":1}}}`
			case "second":
				body = `{"lagran":{"enabled":true,"syn_ack":true,"ack":true,"window_scale":7,"confusion_times":0,"split_number":7,"window_after_split":28960,"window_confusion_last":65535,"window":{"80":8,"443":3}}}`
			default:
				body = `{"lagran":{"enabled":false,"syn_ack":true,"ack":false,"window_scale":7,"confusion_times":0,"split_number":7,"window_after_split":1000,"window_confusion_last":2000,"window":{"80":4,"443":5}}}`
			}
			_, _ = w.Write([]byte(body))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	cfg := config{MasterURL: srv.URL, Token: "1.testtoken"}
	client := srv.Client()
	registered := true
	revs := revisionState{challenges: "c", rules: "r", certificates: "e"}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("first tick: %v", err)
	}
	if pulls != 1 || reloads != 1 || revs.lagran != "first" {
		t.Fatalf("pulls=%d reloads=%d rev=%q", pulls, reloads, revs.lagran)
	}
	if redicfg.LagranBool("enabled", true) || redicfg.LagranBool("ack", true) {
		t.Fatal("expected enabled and ack off")
	}
	if redicfg.LagranWindow("80") != 4 || redicfg.LagranWindow("443") != 5 || redicfg.LagranWindow("8080") != 9 {
		t.Fatalf("windows 80=%d 443=%d 8080=%d", redicfg.LagranWindow("80"), redicfg.LagranWindow("443"), redicfg.LagranWindow("8080"))
	}
	if redicfg.LagranInt("window_after_split", 0) != 1000 {
		t.Fatalf("after %d", redicfg.LagranInt("window_after_split", 0))
	}

	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("unchanged tick: %v", err)
	}
	if pulls != 1 || reloads != 1 {
		t.Fatalf("unexpected second pull pulls=%d reloads=%d", pulls, reloads)
	}

	mu.Lock()
	mode = "second"
	mu.Unlock()
	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("second policy: %v", err)
	}
	if pulls != 2 || reloads != 2 || redicfg.LagranWindow("80") != 8 || !redicfg.LagranBool("enabled", false) {
		t.Fatalf("pulls=%d reloads=%d window=%d enabled=%v", pulls, reloads, redicfg.LagranWindow("80"), redicfg.LagranBool("enabled", false))
	}

	mu.Lock()
	mode = "bad"
	mu.Unlock()
	if err := tick(client, cfg, &registered, &revs); err != nil {
		t.Fatalf("bad policy tick: %v", err)
	}
	if pulls != 3 || reloads != 2 || revs.lagran != "second" || redicfg.LagranWindow("80") != 8 {
		t.Fatalf("bad policy applied pulls=%d reloads=%d rev=%q window=%d", pulls, reloads, revs.lagran, redicfg.LagranWindow("80"))
	}
}
