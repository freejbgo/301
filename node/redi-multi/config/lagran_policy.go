package config

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"strings"
)

// LagranPolicy 是主控下发的一份全局 lagran 设定。
// 不包含队列号、iptables 表和监听端口。端口仍由本机 addr / tls_addr 合并。
type LagranPolicy struct {
	Enabled             bool           `json:"enabled"`
	SynAck              bool           `json:"syn_ack"`
	Ack                 bool           `json:"ack"`
	WindowScale         int            `json:"window_scale"`
	ConfusionTimes      int            `json:"confusion_times"`
	SplitNumber         int            `json:"split_number"`
	WindowAfterSplit    int            `json:"window_after_split"`
	WindowConfusionLast int            `json:"window_confusion_last"`
	Window              map[string]int `json:"window"`
}

func (p LagranPolicy) Validate() error {
	if p.WindowScale < 0 || p.WindowScale > 14 {
		return fmt.Errorf("window_scale must be an integer from 0 to 14")
	}
	if p.ConfusionTimes < 0 || p.ConfusionTimes > 64 {
		return fmt.Errorf("confusion_times must be an integer from 0 to 64")
	}
	if p.SplitNumber < 0 || p.SplitNumber > 1000000 {
		return fmt.Errorf("split_number must be an integer from 0 to 1000000")
	}
	if p.WindowAfterSplit < 1 || p.WindowAfterSplit > 65535 {
		return fmt.Errorf("window_after_split must be an integer from 1 to 65535")
	}
	if p.WindowConfusionLast < 1 || p.WindowConfusionLast > 65535 {
		return fmt.Errorf("window_confusion_last must be an integer from 1 to 65535")
	}
	if err := validatePolicyWindow(p.Window, "80"); err != nil {
		return err
	}
	return validatePolicyWindow(p.Window, "443")
}

func validatePolicyWindow(windows map[string]int, port string) error {
	if windows == nil {
		return fmt.Errorf("window.%s must be an integer from 1 to 65535", port)
	}
	n, ok := windows[port]
	if !ok || n < 1 || n > 65535 {
		return fmt.Errorf("window.%s must be an integer from 1 to 65535", port)
	}
	return nil
}

// ApplyLagranPolicy 写入内存中的 lagran 设定。80 和 443 的窗口以主控为准，其它端口保留本机已有值。
// 校验失败时不改现有设定。
func ApplyLagranPolicy(p LagranPolicy) error {
	if err := p.Validate(); err != nil {
		return err
	}
	lagranMu.Lock()
	defer lagranMu.Unlock()
	if Lagran == nil {
		Lagran = DefaultLagran()
	}
	Lagran["enabled"] = p.Enabled
	Lagran["syn_ack"] = p.SynAck
	Lagran["ack"] = p.Ack
	Lagran["window_scale"] = p.WindowScale
	Lagran["confusion_times"] = p.ConfusionTimes
	Lagran["split_number"] = p.SplitNumber
	Lagran["window_after_split"] = p.WindowAfterSplit
	Lagran["window_confusion_last"] = p.WindowConfusionLast
	windows := map[string]uint16{}
	if existing, ok := Lagran["window"].(map[string]uint16); ok {
		for key, val := range existing {
			windows[key] = val
		}
	}
	windows["80"] = uint16(p.Window["80"])
	windows["443"] = uint16(p.Window["443"])
	Lagran["window"] = windows
	return nil
}

// PersistLagranSection 把主控拥有的键写回 config.json。失败时调用方应保留已经生效的内存设定。
// 不改 lagran.port，避免把监听端口合并结果覆盖成本机原来的写法。
func PersistLagranSection() error {
	path := strings.TrimSpace(loadedConfigPath)
	if path == "" {
		return nil
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	var payload map[string]interface{}
	if err := json.Unmarshal(raw, &payload); err != nil {
		return err
	}
	if payload == nil {
		payload = map[string]interface{}{}
	}
	section, _ := payload["lagran"].(map[string]interface{})
	if section == nil {
		section = map[string]interface{}{}
	}
	for key, val := range lagranFileOverlay() {
		section[key] = val
	}
	payload["lagran"] = section
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", "  ")
	if err := enc.Encode(payload); err != nil {
		return err
	}
	out := buf.Bytes()
	mode := os.FileMode(0644)
	if info, statErr := os.Stat(path); statErr == nil {
		mode = info.Mode()
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, out, mode); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}

func lagranFileOverlay() map[string]interface{} {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	windows := map[string]int{}
	if raw, ok := Lagran["window"].(map[string]uint16); ok {
		for key, val := range raw {
			windows[key] = int(val)
		}
	}
	return map[string]interface{}{
		"enabled":               lagranBoolLocked("enabled", true),
		"syn_ack":               lagranBoolLocked("syn_ack", true),
		"ack":                   lagranBoolLocked("ack", true),
		"window_scale":          lagranIntLocked("window_scale", DefaultWindowScale),
		"confusion_times":       lagranIntLocked("confusion_times", DefaultConfusionTimes),
		"split_number":          lagranIntLocked("split_number", DefaultSplitNumber),
		"window_after_split":    lagranIntLocked("window_after_split", DefaultWindowAfterSplit),
		"window_confusion_last": lagranIntLocked("window_confusion_last", DefaultWindowConfusionLast),
		"window":                windows,
	}
}
