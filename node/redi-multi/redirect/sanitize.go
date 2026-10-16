package redirect

import (
	"net"
	hostutil "redi-multi/util/host"
	"strings"
)

func sanitizeHost(host string) string {
	if strings.ContainsAny(host, "\r\n\x00\t") {
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
	path = strings.TrimSpace(path)
	if path == "" {
		return "/"
	}
	if strings.ContainsAny(path, "\r\n\x00\t") {
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
	if strings.ContainsAny(location, "\r\n\x00\t") {
		return "/"
	}
	return location
}

func sanitizeRawQuery(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" || !strings.HasPrefix(raw, "?") {
		return ""
	}
	if strings.ContainsAny(raw, "\r\n\x00\t") {
		return ""
	}
	return raw
}
