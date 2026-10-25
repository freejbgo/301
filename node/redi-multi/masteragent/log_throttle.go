package masteragent

import (
	"sync"
	"time"

	"github.com/sirupsen/logrus"
)

var (
	warnLogMu   sync.Mutex
	lastWarnLog = map[string]time.Time{}
)

const warnLogMinInterval = time.Minute

func warnThrottled(key string, format string, args ...interface{}) {
	now := time.Now()
	warnLogMu.Lock()
	last := lastWarnLog[key]
	if now.Sub(last) < warnLogMinInterval {
		warnLogMu.Unlock()
		return
	}
	lastWarnLog[key] = now
	warnLogMu.Unlock()
	logrus.Warnf(format, args...)
}
