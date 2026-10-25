package masteragent

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestNoteEmptyCertificateListDebounces(t *testing.T) {
	t.Setenv("REDI_AGENT_EMPTY_CERT_CLEAR_AFTER", "3")
	resetEmptyCertificateStreak()
	for i := 1; i <= 2; i++ {
		if err := noteEmptyCertificateList(); err == nil {
			t.Fatalf("empty %d: expected debounce error", i)
		}
	}
	if err := noteEmptyCertificateList(); err != nil {
		t.Fatalf("third empty: expected allow clear, got %v", err)
	}
}

func TestPullCertificatesEmptyListDebounces(t *testing.T) {
	t.Setenv("REDI_AGENT_EMPTY_CERT_CLEAR_AFTER", "2")
	resetEmptyCertificateStreak()

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method+" "+r.URL.Path == "GET /api/nodes/certificates" {
			_ = json.NewEncoder(w).Encode(map[string]interface{}{"certificates": []interface{}{}})
			return
		}
		http.NotFound(w, r)
	}))
	defer srv.Close()

	cfg := config{MasterURL: srv.URL, Token: "1.testtoken"}
	registered := true
	if err := pullCertificates(srv.Client(), cfg, &registered); err == nil {
		t.Fatal("first empty pull should debounce")
	}
	if err := pullCertificates(srv.Client(), cfg, &registered); err != nil {
		t.Fatalf("second empty pull should clear: %v", err)
	}
}
