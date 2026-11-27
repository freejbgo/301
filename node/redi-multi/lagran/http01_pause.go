package lagran

import (
	"sync"
	"time"

	"github.com/sirupsen/logrus"
)

// 申请证书时主控和证书机构都要在几秒内发完一条很短的 HTTP 请求。
// 窗口改成 1 之后，公网往返一次只能再送 1 字节，请求发不完。
// 节点拉到未过期挑战时暂停改写，挑战清空或暂停超过时限后自己恢复。
const http01PauseMax = 10 * time.Minute

var (
	http01PauseMu  sync.Mutex
	http01Paused   bool
	http01PausedAt time.Time
)

// PauseHTTP01Window 在挑战写入内存之后、向主控上报同步之前调用。
func PauseHTTP01Window() {
	http01PauseMu.Lock()
	defer http01PauseMu.Unlock()
	if !http01Paused {
		logrus.Info("[lagran] HTTP-01 challenge loaded; outbound TCP window rewrite paused")
	}
	http01Paused = true
	http01PausedAt = time.Now()
}

// ResumeHTTP01Window 在挑战从内存清掉之后调用。
func ResumeHTTP01Window() {
	http01PauseMu.Lock()
	defer http01PauseMu.Unlock()
	if http01Paused {
		logrus.Info("[lagran] HTTP-01 challenge cleared; outbound TCP window rewrite resumed")
	}
	http01Paused = false
	http01PausedAt = time.Time{}
}

// ReleaseExpiredHTTP01Pause 由 Agent 轮询调用，避免没有新数据包时暂停一直挂着。
func ReleaseExpiredHTTP01Pause() {
	http01PauseMu.Lock()
	defer http01PauseMu.Unlock()
	releaseExpiredHTTP01PauseLocked()
}

// HTTP01PauseActiveForTest 给 Agent 测试确认暂停是否已经生效。过期的暂停会在这里被放开。
func HTTP01PauseActiveForTest() bool {
	return http01PauseActive()
}

func http01PauseActive() bool {
	http01PauseMu.Lock()
	defer http01PauseMu.Unlock()
	if !http01Paused {
		return false
	}
	if releaseExpiredHTTP01PauseLocked() {
		return false
	}
	return true
}

func releaseExpiredHTTP01PauseLocked() bool {
	if !http01Paused || http01PausedAt.IsZero() {
		return false
	}
	if time.Since(http01PausedAt) < http01PauseMax {
		return false
	}
	http01Paused = false
	http01PausedAt = time.Time{}
	logrus.Warn("[lagran] HTTP-01 pause exceeded 10m; outbound TCP window rewrite resumed")
	return true
}
