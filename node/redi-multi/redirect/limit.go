package redirect

import "github.com/sirupsen/logrus"

const maxConcurrentConnections = 2048

var connSlots = make(chan struct{}, maxConcurrentConnections)

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
	}
}
