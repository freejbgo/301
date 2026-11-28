package config

const (
	// 默认：每个端口窗口 1，Window Scale 7，额外 ACK 0 条，纯 ACK 计次 7。
	// 队列由本进程自己安装，不沿用外部脚本的队列号。
	// 纯 ACK 计满 split_number 之后的窗口默认 28960，最后一条额外 ACK 的窗口默认 65535。
	DefaultWindowScale         = 7
	DefaultConfusionTimes      = 0
	DefaultSplitNumber         = 7
	DefaultWindowAfterSplit    = 28960
	DefaultWindowConfusionLast = 65535
)

// DefaultLagran 在 config.json 未配置 lagran 节时使用。
func DefaultLagran() map[string]interface{} {
	return map[string]interface{}{
		"port":                  "80",
		"syn_ack":               true,
		"ack":                   true,
		"window_scale":          DefaultWindowScale,
		"confusion_times":       DefaultConfusionTimes,
		"split_number":          DefaultSplitNumber,
		"window_after_split":    DefaultWindowAfterSplit,
		"window_confusion_last": DefaultWindowConfusionLast,
	}
}

func LagranBool(key string, defaultVal bool) bool {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	return lagranBoolLocked(key, defaultVal)
}

func lagranBoolLocked(key string, defaultVal bool) bool {
	if Lagran == nil {
		return defaultVal
	}
	v, ok := Lagran[key]
	if !ok || v == nil {
		return defaultVal
	}
	if b, ok := v.(bool); ok {
		return b
	}
	return scalarToBool(v, defaultVal)
}
