package masteragent

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"
)

var (
	emptyRulesMu     sync.Mutex
	emptyRulesStreak int
)

func emptyRulesClearThreshold() int {
	v := strings.TrimSpace(os.Getenv("REDI_AGENT_EMPTY_RULES_CLEAR_AFTER"))
	if v == "" {
		return 3
	}
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return 3
	}
	return n
}

func noteEmptyRedirectRulesList() error {
	emptyRulesMu.Lock()
	emptyRulesStreak++
	streak := emptyRulesStreak
	thresh := emptyRulesClearThreshold()
	emptyRulesMu.Unlock()
	if streak < thresh {
		return fmt.Errorf("empty redirect-rules list from master (debounce %d/%d)", streak, thresh)
	}
	emptyRulesMu.Lock()
	emptyRulesStreak = 0
	emptyRulesMu.Unlock()
	return nil
}

func resetEmptyRedirectRulesStreak() {
	emptyRulesMu.Lock()
	emptyRulesStreak = 0
	emptyRulesMu.Unlock()
}
