package redirect

import (
	"net"
	hostutil "redi-multi/util/host"
	"strings"
)

func sanitizeHost(host string) string {
	if hostutil.ContainsControlChars(host) {
		return ""
	}
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" {
		return ""
	}
	if net.ParseIP(host) != nil {
		return host
	}
	if strings.Contains(host, ":") && !strings.Contains(host, "]") {
		return ""
	}
	canonical := hostutil.CanonicalLookupHost(host)
	if canonical == "" {
		return ""
	}
	return canonical
}

func sanitizeRequestPath(path string) string {
	if hostutil.ContainsControlChars(path) {
		return "/"
	}
	path = strings.TrimSpace(path)
	if path == "" {
		return "/"
	}
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	if strings.HasPrefix(path, "//") {
		return "/"
	}
	if strings.Contains(path, "\\") {
		return "/"
	}
	return path
}

func sanitizeLocationURL(location string) string {
	if hostutil.ContainsControlChars(location) {
		return "/"
	}
	return location
}

func sanitizeRawQuery(raw string) string {
	if hostutil.ContainsControlChars(raw) {
		return ""
	}
	raw = strings.TrimSpace(raw)
	if raw == "" || !strings.HasPrefix(raw, "?") {
		return ""
	}
	return raw
}
