package config

import (
	"encoding/json"
	"fmt"
	"log"
	"strconv"
	"strings"
)

func normalizeLagranMap() {
	if Lagran == nil {
		return
	}
	if v, ok := Lagran["port"]; ok && v != nil {
		Lagran["port"] = scalarToPortString(v)
	}
	for _, key := range []string{"enabled", "syn_ack", "ack", "psh_ack", "fin_ack"} {
		if v, ok := Lagran[key]; ok && v != nil {
			Lagran[key] = scalarToBool(v, true)
		}
	}
	if err := applyLagranWindows(); err != nil {
		log.Fatalf("config.json: %v", err)
	}
	if err := applyLagranGeneva(); err != nil {
		log.Fatalf("config.json: %v", err)
	}
}

// applyLagranGeneva 解析窗口相关整数。
// 缺省是 Window Scale 7、额外 ACK 0 条、纯 ACK 计次 7，计满后窗口 28960，最后一条额外 ACK 窗口 65535。
// psh_ack / fin_ack 仍接受旧配置，但不再安装规则，也不再改这两类包。
func applyLagranGeneva() error {
	if Lagran == nil {
		return nil
	}
	fields := []struct {
		key           string
		def, min, max int
	}{
		{"window_scale", DefaultWindowScale, 0, 14},
		{"confusion_times", DefaultConfusionTimes, 0, 64},
		{"split_number", DefaultSplitNumber, 0, 1000000},
		{"window_after_split", DefaultWindowAfterSplit, 1, 65535},
		{"window_confusion_last", DefaultWindowConfusionLast, 1, 65535},
	}
	for _, field := range fields {
		v, ok := Lagran[field.key]
		if !ok || v == nil {
			Lagran[field.key] = field.def
			continue
		}
		n, err := scalarToBoundedInt(v, field.min, field.max)
		if err != nil {
			return fmt.Errorf("lagran.%s: %w", field.key, err)
		}
		Lagran[field.key] = n
	}
	return nil
}

func scalarToBoundedInt(v interface{}, min, max int) (int, error) {
	hint := fmt.Sprintf("must be an integer from %d to %d", min, max)
	var n int
	switch t := v.(type) {
	case int:
		n = t
	case int64:
		if t < int64(min) || t > int64(max) {
			return 0, fmt.Errorf("%s", hint)
		}
		return int(t), nil
	case float64:
		if t != float64(int(t)) {
			return 0, fmt.Errorf("%s", hint)
		}
		n = int(t)
	case json.Number:
		parsed, err := t.Int64()
		if err != nil {
			return 0, fmt.Errorf("%s", hint)
		}
		return scalarToBoundedInt(parsed, min, max)
	case string:
		parsed, err := strconv.Atoi(strings.TrimSpace(t))
		if err != nil {
			return 0, fmt.Errorf("%s", hint)
		}
		n = parsed
	default:
		return 0, fmt.Errorf("%s", hint)
	}
	if n < min || n > max {
		return 0, fmt.Errorf("%s", hint)
	}
	return n, nil
}

// LagranInt 读取已经规范化的整数。缺省或类型不符时返回 defaultVal。
func LagranInt(key string, defaultVal int) int {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	return lagranIntLocked(key, defaultVal)
}

func lagranIntLocked(key string, defaultVal int) int {
	if Lagran == nil {
		return defaultVal
	}
	v, ok := Lagran[key]
	if !ok || v == nil {
		return defaultVal
	}
	switch t := v.(type) {
	case int:
		return t
	case float64:
		if t != float64(int(t)) {
			return defaultVal
		}
		return int(t)
	default:
		return defaultVal
	}
}

// defaultLagranWindow 是某个源端口没有写在 lagran.window 里时使用的出站窗口。
const defaultLagranWindow uint16 = 1

// applyLagranWindows 把 lagran.window 收成「端口 → 窗口」。缺省或未列出的端口保持 1。
func applyLagranWindows() error {
	if Lagran == nil {
		return nil
	}
	raw, ok := Lagran["window"]
	if !ok || raw == nil {
		delete(Lagran, "window")
		return nil
	}
	if _, already := raw.(map[string]uint16); already {
		return nil
	}
	src, ok := raw.(map[string]interface{})
	if !ok {
		return fmt.Errorf("lagran.window must be an object of port to window size")
	}
	out := make(map[string]uint16, len(src))
	for key, val := range src {
		port, err := canonicalTCPPort(key)
		if err != nil {
			return fmt.Errorf("lagran.window key %q: %w", key, err)
		}
		w, err := scalarToWindow(val)
		if err != nil {
			return fmt.Errorf("lagran.window[%s]: %w", port, err)
		}
		out[port] = w
	}
	Lagran["window"] = out
	return nil
}

// LagranWindow 返回该源端口的 window_size。SYN+ACK、混淆 ACK 和尚未超过 split_number 的纯 ACK 用它。
func LagranWindow(port string) uint16 {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	return lagranWindowLocked(port)
}

func lagranWindowLocked(port string) uint16 {
	if Lagran == nil {
		return defaultLagranWindow
	}
	raw, ok := Lagran["window"]
	if !ok || raw == nil {
		return defaultLagranWindow
	}
	windows, ok := raw.(map[string]uint16)
	if !ok {
		return defaultLagranWindow
	}
	if w, ok := windows[port]; ok {
		return w
	}
	return defaultLagranWindow
}

// LagranWindowSummary 按当前生效端口列出窗口，供启动日志使用。
func LagranWindowSummary() string {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	ports := strings.Split(lagranSportLocked(), ",")
	parts := make([]string, 0, len(ports))
	for _, port := range ports {
		port = strings.TrimSpace(port)
		if port == "" {
			continue
		}
		parts = append(parts, fmt.Sprintf("%s=%d", port, lagranWindowLocked(port)))
	}
	return strings.Join(parts, ",")
}

func canonicalTCPPort(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	n, err := strconv.Atoi(raw)
	if err != nil || n < 1 || n > 65535 {
		return "", fmt.Errorf("not a TCP port")
	}
	return strconv.Itoa(n), nil
}

func scalarToWindow(v interface{}) (uint16, error) {
	const hint = "window must be an integer from 1 to 65535"
	switch t := v.(type) {
	case float64:
		if t != float64(uint16(t)) || t < 1 || t > 65535 {
			return 0, fmt.Errorf("%s", hint)
		}
		return uint16(t), nil
	case int:
		if t < 1 || t > 65535 {
			return 0, fmt.Errorf("%s", hint)
		}
		return uint16(t), nil
	case int64:
		if t < 1 || t > 65535 {
			return 0, fmt.Errorf("%s", hint)
		}
		return uint16(t), nil
	case json.Number:
		return scalarToWindow(strings.TrimSpace(t.String()))
	case string:
		n, err := strconv.Atoi(strings.TrimSpace(t))
		if err != nil {
			return 0, fmt.Errorf("%s", hint)
		}
		return scalarToWindow(n)
	default:
		return 0, fmt.Errorf("%s", hint)
	}
}

func lagranPortFromMap() string {
	if Lagran == nil {
		return ""
	}
	v, ok := Lagran["port"]
	if !ok || v == nil {
		return ""
	}
	return scalarToPortString(v)
}

func scalarToPortString(v interface{}) string {
	switch t := v.(type) {
	case string:
		return strings.TrimSpace(t)
	case float64:
		return strconv.FormatInt(int64(t), 10)
	case int:
		return strconv.Itoa(t)
	case int64:
		return strconv.FormatInt(t, 10)
	case json.Number:
		return strings.TrimSpace(t.String())
	default:
		log.Fatalf("config.json: lagran.port must be a string or number")
		return ""
	}
}

func scalarToBool(v interface{}, defaultVal bool) bool {
	switch t := v.(type) {
	case bool:
		return t
	case string:
		s := strings.TrimSpace(strings.ToLower(t))
		switch s {
		case "1", "true", "yes", "on":
			return true
		case "0", "false", "no", "off":
			return false
		default:
			log.Fatalf("config.json: lagran boolean: invalid string %q", t)
			return defaultVal
		}
	case float64:
		return t != 0
	case int:
		return t != 0
	default:
		log.Fatalf("config.json: lagran flag must be boolean")
		return defaultVal
	}
}
