package config

import (
	"net"
	"strconv"
	"strings"
)

// LagranSport 供 lagran / iptables 使用（Init 时已合并，此处仅规范化读取）。
func LagranSport() string {
	lagranMu.RLock()
	defer lagranMu.RUnlock()
	return lagranSportLocked()
}

func lagranSportLocked() string {
	if Lagran == nil {
		return "80"
	}
	raw, ok := Lagran["port"].(string)
	if !ok || strings.TrimSpace(raw) == "" {
		return "80"
	}
	return NormalizePortList(raw)
}

func portFromListenAddr(listen string) string {
	listen = strings.TrimSpace(listen)
	if listen == "" {
		return ""
	}
	host, port, err := net.SplitHostPort(listen)
	if err != nil {
		// 误写 ::80 / ::443（缺方括号）时与 lagran_ports.py 一致，仍解析出端口数字。
		if strings.HasPrefix(listen, "::") {
			rest := strings.TrimPrefix(listen, "::")
			if rest != "" && !strings.Contains(rest, ":") {
				return rest
			}
		}
		// 仅端口，如 ":80" 或 "80"
		if strings.HasPrefix(listen, ":") {
			return strings.TrimPrefix(listen, ":")
		}
		return listen
	}
	if port != "" {
		return port
	}
	return portFromListenAddr(host)
}

// MergeListenerPorts 把 HTTP/HTTPS 监听端口并入 lagran.port（用户可只写 "80"，会自动补上 tls 端口）。
func MergeListenerPorts(lagranPorts string, httpAddr string, tlsAddr string) string {
	set := map[string]struct{}{}
	for _, p := range strings.Split(lagranPorts, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			set[p] = struct{}{}
		}
	}
	for _, p := range []string{portFromListenAddr(httpAddr), portFromListenAddr(tlsAddr)} {
		if p != "" {
			set[p] = struct{}{}
		}
	}
	if len(set) == 0 {
		return "80"
	}
	keys := make([]string, 0, len(set))
	for p := range set {
		keys = append(keys, p)
	}
	sortPortStrings(keys)
	return strings.Join(keys, ",")
}

func NormalizePortList(list string) string {
	parts := strings.Split(list, ",")
	var out []string
	seen := map[string]struct{}{}
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		if _, ok := seen[p]; ok {
			continue
		}
		seen[p] = struct{}{}
		out = append(out, p)
	}
	sortPortStrings(out)
	return strings.Join(out, ",")
}

func sortPortStrings(ports []string) {
	for i := 0; i < len(ports); i++ {
		for j := i + 1; j < len(ports); j++ {
			ai, _ := strconv.Atoi(ports[i])
			aj, _ := strconv.Atoi(ports[j])
			if aj < ai {
				ports[i], ports[j] = ports[j], ports[i]
			}
		}
	}
}

func LagranPortSet() map[string]bool {
	m := map[string]bool{}
	for _, p := range strings.Split(LagranSport(), ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			m[p] = true
		}
	}
	return m
}
