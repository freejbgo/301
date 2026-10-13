package redirecturl

import "net/url"

// IsAllowed 仅允许无用户信息的 http/https 跳转目标。
func IsAllowed(raw string) bool {
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
