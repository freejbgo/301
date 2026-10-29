package masteragent

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"sync"
)

var (
	emptyCertMu     sync.Mutex
	emptyCertStreak int
)

func emptyCertClearThreshold() int {
	v := strings.TrimSpace(os.Getenv("REDI_AGENT_EMPTY_CERT_CLEAR_AFTER"))
	if v == "" {
		return 3
	}
	n, err := strconv.Atoi(v)
	if err != nil || n < 1 {
		return 3
	}
	return n
}

func noteEmptyCertificateList() error {
	emptyCertMu.Lock()
	emptyCertStreak++
	streak := emptyCertStreak
	thresh := emptyCertClearThreshold()
	emptyCertMu.Unlock()
	if streak < thresh {
		return fmt.Errorf("%w (%d/%d)", errEmptyListDebounce, streak, thresh)
	}
	emptyCertMu.Lock()
	emptyCertStreak = 0
	emptyCertMu.Unlock()
	return nil
}

func resetEmptyCertificateStreak() {
	emptyCertMu.Lock()
	emptyCertStreak = 0
	emptyCertMu.Unlock()
}
