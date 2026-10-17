package masteragent

import "redi-multi/util/host"

// sanitizeAckError 与主控 OpsDisplaySanitizer::logSnippet 对齐（去控制字符并限长）。
func sanitizeAckError(msg string) string {
	return host.LogSnippet(msg, 2000)
}
