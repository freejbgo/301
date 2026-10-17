package redirecturl

import (
	"net/url"

	"redi-multi/util/host"
)

// IsAllowed 仅允许无用户信息的 http/https 跳转目标。
func IsAllowed(raw string) bool {
	if host.ContainsControlChars(raw) {
		return false
	}
	u, err := url.Parse(raw)
	if err != nil {
		return false
	}
	if u.User != nil {
		return false
	}
	switch u.Scheme {
	case "http", "https":
		return u.Host != ""
	default:
		return false
	}
}
