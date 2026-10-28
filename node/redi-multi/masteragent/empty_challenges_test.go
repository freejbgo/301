package masteragent

import "testing"

func TestEmptyChallengeListDebouncesBeforeClear(t *testing.T) {
	resetEmptyChallengeStreak()
	thresh := emptyChallengeClearThreshold()
	for i := 1; i < thresh; i++ {
		if err := noteEmptyChallengeList(); err == nil {
			t.Fatalf("expected debounce error on attempt %d", i)
		}
	}
	if err := noteEmptyChallengeList(); err != nil {
		t.Fatalf("expected clear after %d empty pulls: %v", thresh, err)
	}
}
