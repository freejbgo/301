package masteragent

import (
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"redi-multi/lagran"
)

func TestPullChallengesPausesWindowRewriteBeforeSync(t *testing.T) {
	lagran.ResumeHTTP01Window()
	t.Cleanup(lagran.ResumeHTTP01Window)

	var synced atomic.Bool
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/nodes/acme-challenges":
			w.Header().Set("Content-Type", "application/json")
			_, _ = io.WriteString(w, `{"challenges":[{"domain_name":"bb.example.com","token":"tok123","response_body":"challenge-body"}]}`)
		case "/api/nodes/challenges-synced":
			if !lagran.HTTP01PauseActiveForTest() {
				t.Errorf("window rewrite still active when sync is reported")
			}
			synced.Store(true)
			w.WriteHeader(http.StatusOK)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	registered := true
	err := pullChallenges(&http.Client{}, config{MasterURL: srv.URL, Token: "token"}, &registered)
	if err != nil {
		t.Fatal(err)
	}
	if !synced.Load() {
		t.Fatal("expected challenges-synced")
	}
}

func TestPullChallengesResumesAfterEmptyStreak(t *testing.T) {
	lagran.PauseHTTP01Window()
	t.Cleanup(lagran.ResumeHTTP01Window)
	resetEmptyChallengeStreak()
	t.Cleanup(resetEmptyChallengeStreak)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = io.WriteString(w, `{"challenges":[]}`)
	}))
	defer srv.Close()

	registered := true
	cfg := config{MasterURL: srv.URL, Token: "token"}
	thresh := emptyChallengeClearThreshold()
	for i := 1; i < thresh; i++ {
		err := pullChallenges(&http.Client{}, cfg, &registered)
		if !errors.Is(err, errEmptyListDebounce) {
			t.Fatalf("attempt %d: %v", i, err)
		}
		if !lagran.HTTP01PauseActiveForTest() {
			t.Fatalf("attempt %d resumed before the empty streak cleared", i)
		}
	}
	if err := pullChallenges(&http.Client{}, cfg, &registered); err != nil {
		t.Fatal(err)
	}
	if lagran.HTTP01PauseActiveForTest() {
		t.Fatal("expected window rewrite to resume after the challenge list cleared")
	}
}
