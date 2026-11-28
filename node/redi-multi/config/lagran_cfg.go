package config

const (
	// DefaultWindowScale、DefaultConfusionTimes、DefaultSplitNumber 与 geneva.py 的默认参数一致。
	DefaultWindowScale    = 7
	DefaultConfusionTimes = 7
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
