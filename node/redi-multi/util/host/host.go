package host

import (
	"strings"

	"golang.org/x/net/idna"
)

// ContainsControlChars 与主控 OpsDisplaySanitizer::containsControlChars 对齐。
func ContainsControlChars(s string) bool {
	return strings.ContainsAny(s, "\r\n\x00\t")
}

// SanitizeUserAgent 与主控 redirect 路径一致：拒控制字符、trim、最长 512。
func SanitizeUserAgent(ua string) string {
	if ContainsControlChars(ua) {
		return ""
	}
	ua = strings.TrimSpace(ua)
	if len(ua) > 512 {
		return ua[:512]
	}

	return ua
}

// CanonicalLookupHost 与主控 HostNameValidator::toCanonicalAscii 对齐（小写 + punycode）。
func CanonicalLookupHost(raw string) string {
	if ContainsControlChars(raw) {
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
