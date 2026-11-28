package config

const (
	// 对应节点上的实际跑法：python3 geneva.py -q 100 -w 1 -s 7 -c 0 -n 7
	// -q 100 不在这里：队列由本进程自己安装。-w 1 写在 lagran.window。
	// -c 0 表示不发额外 ACK。脚本文件里的默认值（confusion 7 次）不是线上用法。
	// 纯 ACK 计满 split_number 之后的窗口，以及最后一条混淆 ACK 的窗口，geneva 写死为 28960 和 65535。
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
