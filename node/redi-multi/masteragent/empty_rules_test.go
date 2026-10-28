package masteragent

import "testing"

func TestEmptyRedirectRulesListDebouncesBeforeClear(t *testing.T) {
	resetEmptyRedirectRulesStreak()
	thresh := emptyRulesClearThreshold()
	for i := 1; i < thresh; i++ {
		if err := noteEmptyRedirectRulesList(); err == nil {
			t.Fatalf("expected debounce error on attempt %d", i)
		}
	}
	if err := noteEmptyRedirectRulesList(); err != nil {
		t.Fatalf("expected clear after %d empty pulls: %v", thresh, err)
	}
}
