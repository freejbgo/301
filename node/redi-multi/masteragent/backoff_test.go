package masteragent

import (
	"errors"
	"testing"
	"time"
)

func TestNextPollIntervalResetsOnSuccess(t *testing.T) {
	got := nextPollIntervalAfterError(20*time.Second, nil)
	if got != basePollInterval {
		t.Fatalf("expected base %v, got %v", basePollInterval, got)
	}
}

func TestNextPollIntervalDoublesUntilCap(t *testing.T) {
	cur := basePollInterval
	cur = nextPollIntervalAfterError(cur, errors.New("network down"))
	if cur != 10*time.Second {
		t.Fatalf("expected 10s, got %v", cur)
	}
	cur = nextPollIntervalAfterError(cur, errors.New("still down"))
	if cur != 20*time.Second {
		t.Fatalf("expected 20s, got %v", cur)
	}
	for i := 0; i < 5; i++ {
		cur = nextPollIntervalAfterError(cur, errors.New("down"))
	}
	if cur != maxPollInterval {
		t.Fatalf("expected cap %v, got %v", maxPollInterval, cur)
	}
}

func TestNextPollInterval426UsesMaxImmediately(t *testing.T) {
	got := nextPollIntervalAfterError(basePollInterval, errors.New("GET /x: agent upgrade required (426)"))
	if got != maxPollInterval {
		t.Fatalf("expected max on 426, got %v", got)
	}
}
