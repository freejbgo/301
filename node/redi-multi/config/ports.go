package config

import (
	"fmt"
	"strconv"
	"strings"
)

// LagranSport 供 lagran / iptables 使用的出站源端口列表（逗号分隔，无空格）。
func LagranSport() string {
	if Lagran == nil {
		return ""
	}
	raw, ok := Lagran["port"].(string)
	if !ok {
		raw = ""
	}
	return NormalizePortList(MergeListenerPorts(raw, Addr, TlsAddr))
}

func portFromListenAddr(listen string) string {
	listen = strings.TrimSpace(listen)
	if listen == "" {
		return ""
	}
	if strings.HasPrefix(listen, ":") {
		return strings.TrimPrefix(listen, ":")
	}
	_, port, err := splitHostPort(listen)
	if err != nil || port == "" {
		return ""
	}
	return port
}

func splitHostPort(addr string) (host, port string, err error) {
	if !strings.Contains(addr, ":") {
		return "", addr, nil
	}
	i := strings.LastIndex(addr, ":")
	if i < 0 {
		return "", "", fmt.Errorf("invalid listen address")
	}
	return addr[:i], addr[i+1:], nil
}

// MergeListenerPorts 把 HTTP/HTTPS 监听端口并入 lagran.port（用户可只写 "80"，会自动补上 443）。
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
	// 数字端口按数值排序，便于阅读与对比
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
