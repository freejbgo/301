package redirect

import "github.com/sirupsen/logrus"

const maxConcurrentConnections = 2048

var connSlots = make(chan struct{}, maxConcurrentConnections)

// TryAcquireConnection 在 accept 循环中预占连接槽，避免在已达上限时仍创建 goroutine。
func TryAcquireConnection() bool {
	return acquireConnection()
}

func acquireConnection() bool {
	select {
	case connSlots <- struct{}{}:
		return true
	default:
		logrus.Warn("[redirect] connection limit reached, dropping client")
		return false
	}
}

func releaseConnection() {
	select {
	case <-connSlots:
	default:
		logrus.Warn("[redirect] releaseConnection without matching acquire")
	}
}
