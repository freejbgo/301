package acme

import (
	"strings"
	"sync"

	"redi-multi/util/host"
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

func Http01ResponseBodyValid(raw string) bool {
	if host.ContainsControlChars(raw) {
		return false
	}

	return strings.TrimSpace(raw) != ""
}

func Http01TokenValid(raw string) bool {
	if host.ContainsControlChars(raw) {
		return false
	}

	return ValidChallengeToken(strings.TrimSpace(raw))
}

func ValidChallengeToken(token string) bool {
	if token == "" || len(token) > 128 {
		return false
	}
	for _, r := range token {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', r == '_', r == '-':
		default:
			return false
		}
	}
	return true
}

func ReplaceAll(items []Item) {
	mu.Lock()
	byDomain = map[string]challenge{}
	for _, it := range items {
		d := host.AcmeHostKey(it.DomainName)
		if d == "" || !Http01TokenValid(it.Token) || !Http01ResponseBodyValid(it.ResponseBody) {
			continue
		}
		t := strings.TrimSpace(it.Token)
		resp := strings.TrimSpace(it.ResponseBody)
		byDomain[d] = challenge{token: t, response: resp}
	}
	mu.Unlock()
}

func ResponseForPath(hostName, path string) (body string, ok bool) {
	hostName = host.CanonicalLookupHost(hostName)
	if hostName == "" {
		return "", false
	}
	if host.ContainsControlChars(path) {
		return "", false
	}
	path = strings.TrimSpace(path)
	const prefix = "/.well-known/acme-challenge/"
	if !strings.HasPrefix(path, prefix) {
		return "", false
	}
	token := strings.TrimPrefix(path, prefix)
	token = strings.Trim(token, "/")
	if !Http01TokenValid(token) {
		return "", false
	}
	t := strings.TrimSpace(token)
	mu.RLock()
	if ch, found := byDomain[hostName]; found && ch.token == t {
		mu.RUnlock()
		return ch.response, true
	}
	mu.RUnlock()
	return "", false
}
