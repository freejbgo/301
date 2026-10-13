package redirect

import (
	"net"
	"strings"
)

func sanitizeHost(host string) string {
	host = strings.ToLower(strings.TrimSpace(host))
	if host == "" {
		return ""
	}
	if strings.ContainsAny(host, "\r\n\x00") {
		return ""
	}
	if net.ParseIP(host) != nil {
		return host
	}
	if strings.Contains(host, ":") && !strings.Contains(host, "]") {
		return ""
	}
	return host
}

func sanitizeRequestPath(path string) string {
	path = strings.TrimSpace(path)
	if path == "" {
		return "/"
	}
	if strings.ContainsAny(path, "\r\n\x00") {
		return "/"
	}
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	if strings.HasPrefix(path, "//") {
		return "/"
	}
	return path
}

func sanitizeLocationURL(location string) string {
	if strings.ContainsAny(location, "\r\n\x00") {
		return "/"
	}
	return location
}
