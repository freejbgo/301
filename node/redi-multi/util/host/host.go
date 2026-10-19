package host

import (
	"net"
	"strings"

	"golang.org/x/net/idna"
)

// ContainsControlChars 与主控 OpsDisplaySanitizer::containsControlChars 对齐。
func ContainsControlChars(s string) bool {
	return strings.ContainsAny(s, "\r\n\x00\t")
}

// StripControlChars 移除 ContainsControlChars 所检测的字符。
func StripControlChars(s string) string {
	if !ContainsControlChars(s) {
		return s
	}
	return strings.Map(func(r rune) rune {
		switch r {
		case '\r', '\n', '\x00', '\t':
			return -1
		default:
			return r
		}
	}, s)
}

// LogSnippet 与主控 OpsDisplaySanitizer::logSnippet 对齐（去控制字符、trim、限长）。
func LogSnippet(s string, maxLen int) string {
	if maxLen <= 0 {
		return ""
	}
	text := strings.TrimSpace(StripControlChars(s))
	if len(text) > maxLen {
		return text[:maxLen]
	}

	return text
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

// SanitizeClientIP 用于限流 map 键与 c.js 的 cip（拒控制字符并 trim）。
func SanitizeClientIP(ip string) string {
	if ContainsControlChars(ip) {
		return ""
	}
	ip = strings.TrimSpace(ip)
	if ip == "" {
		return ""
	}
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return ip
	}
	if v4 := parsed.To4(); v4 != nil {
		return v4.String()
	}

	return ip
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
