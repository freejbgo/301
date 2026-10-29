package masteragent

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"
)

var (
	emptyChallengeMu     sync.Mutex
	emptyChallengeStreak int
)

func emptyChallengeClearThreshold() int {
	v := strings.TrimSpace(os.Getenv("REDI_AGENT_EMPTY_CHALLENGE_CLEAR_AFTER"))
	if v == "" {
		return 3
	}
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return 3
	}
	return n
}

func noteEmptyChallengeList() error {
	emptyChallengeMu.Lock()
	emptyChallengeStreak++
	streak := emptyChallengeStreak
	thresh := emptyChallengeClearThreshold()
	emptyChallengeMu.Unlock()
	if streak < thresh {
		return fmt.Errorf("%w (%d/%d)", errEmptyListDebounce, streak, thresh)
	}
	emptyChallengeMu.Lock()
	emptyChallengeStreak = 0
	emptyChallengeMu.Unlock()
	return nil
}

func resetEmptyChallengeStreak() {
	emptyChallengeMu.Lock()
	emptyChallengeStreak = 0
	emptyChallengeMu.Unlock()
}
