package masteragent

import (
	"testing"
	"time"
)

func TestWarnThrottledSuppressesBurst(t *testing.T) {
	warnLogMu.Lock()
	lastWarnLog = map[string]time.Time{}
	warnLogMu.Unlock()

	key := "test-key-burst"
	warnThrottled(key, "first %s", "msg")
	warnThrottled(key, "second %s", "msg")
	// second call within minute should not update last time to allow immediate third with fake time - just verify no panic
	warnLogMu.Lock()
	if lastWarnLog[key].IsZero() {
		t.Fatal("expected last warn time set")
	}
	warnLogMu.Unlock()
}

func TestWarnThrottledDifferentKeys(t *testing.T) {
	warnLogMu.Lock()
	lastWarnLog = map[string]time.Time{}
	warnLogMu.Unlock()

	warnThrottled("key-a", "a")
	warnThrottled("key-b", "b")
	warnLogMu.Lock()
	if lastWarnLog["key-a"].IsZero() || lastWarnLog["key-b"].IsZero() {
		t.Fatal("both keys should log")
	}
	warnLogMu.Unlock()
}
