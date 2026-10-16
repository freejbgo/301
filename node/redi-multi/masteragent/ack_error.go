package masteragent

import "strings"

// sanitizeAckError 与主控 OpsDisplaySanitizer::logSnippet 对齐（去控制字符并限长）。
func sanitizeAckError(msg string) string {
	msg = strings.ReplaceAll(msg, "\r", "")
	msg = strings.ReplaceAll(msg, "\n", "")
	msg = strings.ReplaceAll(msg, "\x00", "")
	msg = strings.ReplaceAll(msg, "\t", "")
	msg = strings.TrimSpace(msg)
	if len(msg) > 2000 {
		return msg[:2000]
	}

	return msg
}
