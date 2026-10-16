package rules

import (
	"strings"
	"sync"
)

var (
	mu          sync.RWMutex
	forceHTTPS  = map[string]bool{}
)

func ReplaceForceHTTPS(items map[string]bool) {
	mu.Lock()
	forceHTTPS = map[string]bool{}
	for host, v := range items {
		h := strings.ToLower(strings.TrimSpace(host))
		if h == "" || strings.ContainsAny(h, "\r\n\x00") {
			continue
		}
		forceHTTPS[h] = v
	}
	mu.Unlock()
}

func ForceHTTPS(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	mu.RLock()
	v, ok := forceHTTPS[host]
	mu.RUnlock()
	return ok && v
}
