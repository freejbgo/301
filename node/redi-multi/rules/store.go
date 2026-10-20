package rules

import (
	"sync"

	hostutil "redi-multi/util/host"
)

var (
	mu          sync.RWMutex
	forceHTTPS  = map[string]bool{}
)

func ReplaceForceHTTPS(items map[string]bool) {
	mu.Lock()
	forceHTTPS = map[string]bool{}
	for host, v := range items {
		if hostutil.ContainsControlChars(host) {
			continue
		}
		h := hostutil.AcmeHostKey(host)
		if h == "" {
			continue
		}
		forceHTTPS[h] = v
	}
	mu.Unlock()
}

func ForceHTTPS(host string) bool {
	if hostutil.ContainsControlChars(host) {
		return false
	}
	host = hostutil.AcmeHostKey(host)
	if host == "" {
		return false
	}
	mu.RLock()
	v, ok := forceHTTPS[host]
	mu.RUnlock()
	return ok && v
}
