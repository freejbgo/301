package acme

import (
	"strings"
	"sync"
)

type challenge struct {
	token    string
	response string
}

type Item struct {
	DomainName   string
	Token        string
	ResponseBody string
}

var (
	mu         sync.RWMutex
	byDomain   = map[string]challenge{}
)

func ReplaceAll(items []Item) {
	mu.Lock()
	byDomain = map[string]challenge{}
	for _, it := range items {
		d := strings.ToLower(strings.TrimSpace(it.DomainName))
		t := strings.TrimSpace(it.Token)
		if d == "" || t == "" {
			continue
		}
		byDomain[d] = challenge{token: t, response: it.ResponseBody}
	}
	mu.Unlock()
}

func ResponseForPath(host, path string) (body string, ok bool) {
	host = strings.ToLower(strings.TrimSpace(host))
	path = strings.TrimSpace(path)
	const prefix = "/.well-known/acme-challenge/"
	if !strings.HasPrefix(path, prefix) {
		return "", false
	}
	token := strings.TrimPrefix(path, prefix)
	token = strings.Trim(token, "/")
	if token == "" {
		return "", false
	}
	mu.RLock()
	if ch, found := byDomain[host]; found && ch.token == token {
		mu.RUnlock()
		return ch.response, true
	}
	mu.RUnlock()
	return "", false
}
