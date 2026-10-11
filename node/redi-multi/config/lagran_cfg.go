package config

// DefaultLagran 在 config.json 未配置 lagran 节时使用。
func DefaultLagran() map[string]interface{} {
	return map[string]interface{}{
		"port":     "80",
		"syn_ack":  true,
		"ack":      true,
		"psh_ack":  true,
		"fin_ack":  true,
	}
}

func LagranBool(key string, defaultVal bool) bool {
	if Lagran == nil {
		return defaultVal
	}
	v, ok := Lagran[key].(bool)
	if !ok {
		return defaultVal
	}
	return v
}
