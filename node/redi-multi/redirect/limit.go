package redirect

const maxConcurrentConnections = 2048

var connSlots = make(chan struct{}, maxConcurrentConnections)

func acquireConnection() bool {
	select {
	case connSlots <- struct{}{}:
		return true
	default:
		return false
	}
}

func releaseConnection() {
	select {
	case <-connSlots:
	default:
	}
}
