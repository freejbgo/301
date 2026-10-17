package host

import (
	"strings"
)

// AcmeHostKey 与主控 DomainRecordNormalizer::acmeHostKey 对齐（去 scheme/路径后 punycode）。
func AcmeHostKey(raw string) string {
	if ContainsControlChars(raw) {
		return ""
	}
	s := strings.TrimSpace(raw)
	if s == "" {
		return ""
	}
	s = strings.TrimSuffix(s, "/")
	lower := strings.ToLower(s)
	if strings.HasPrefix(lower, "https://") {
		s = s[8:]
	} else if strings.HasPrefix(lower, "http://") {
		s = s[7:]
	}
	if strings.ContainsAny(s, "/?#") {
		return ""
	}

	canonical := CanonicalLookupHost(s)
	if !isAcmeHostLabel(canonical) {
		return ""
	}

	return canonical
}

func isAcmeHostLabel(host string) bool {
	if host == "" || len(host) > 253 {
		return false
	}
	if strings.Contains(host, " ") {
		return false
	}
	for _, label := range strings.Split(host, ".") {
		if label == "" || len(label) > 63 {
			return false
		}
		for _, r := range label {
			switch {
			case r >= 'a' && r <= 'z', r >= '0' && r <= '9', r == '-':
			default:
				return false
			}
		}
		if strings.HasPrefix(label, "-") || strings.HasSuffix(label, "-") {
			return false
		}
	}

	return true
}
