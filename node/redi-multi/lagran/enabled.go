package lagran

import (
	"os"
	"redi-multi/config"
	"strings"
)

// Enabled 是否启动 lagran（NFQUEUE / iptables）。可用 env 或 config.json 关闭以便非特权调试。
func Enabled() bool {
	v := strings.TrimSpace(os.Getenv("REDI_LAGRAN_DISABLED"))
	if v == "1" || strings.EqualFold(v, "true") || strings.EqualFold(v, "yes") {
		return false
	}
	return config.LagranBool("enabled", true)
}
