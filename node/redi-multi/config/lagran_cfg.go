package config

const (
	// 对应节点上的实际跑法：python3 geneva.py -q 100 -w 1 -s 7 -c 0 -n 7
	// -q 100 不在这里：队列由本进程自己安装。-w 1 写在 lagran.window。
	// -c 0 表示不发额外 ACK。脚本文件里的默认值（confusion 7 次）不是线上用法。
	DefaultWindowScale    = 7
	DefaultConfusionTimes = 0
	DefaultSplitNumber    = 7
)

// DefaultLagran 在 config.json 未配置 lagran 节时使用。
func DefaultLagran() map[string]interface{} {
	return map[string]interface{}{
		"port":            "80",
		"syn_ack":         true,
		"ack":             true,
		"window_scale":    DefaultWindowScale,
		"confusion_times": DefaultConfusionTimes,
		"split_number":    DefaultSplitNumber,
	}
}

func LagranBool(key string, defaultVal bool) bool {
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
