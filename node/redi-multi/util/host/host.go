package host

import (
	"strings"

	"golang.org/x/net/idna"
)

// CanonicalLookupHost 与主控 HostNameValidator::toCanonicalAscii 对齐（小写 + punycode）。
func CanonicalLookupHost(raw string) string {
	if strings.ContainsAny(raw, "\r\n\x00") {
		return ""
	}
	host := strings.ToLower(strings.TrimSpace(raw))
	host = strings.Trim(host, ".")
	if host == "" {
		return ""
	}
	ascii, err := idna.Lookup.ToASCII(host)
	if err != nil {
		return host
	}
	return strings.ToLower(ascii)
}
