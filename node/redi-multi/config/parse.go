package config

import (
	"log"
	"os"
	"strings"
)

func jsonBool(payload map[string]interface{}, key string, defaultVal bool) bool {
	v, ok := payload[key]
	if !ok || v == nil {
		return defaultVal
	}
	switch t := v.(type) {
	case bool:
		return t
	default:
		log.Fatalf("config.json: %q must be a boolean", key)
		return defaultVal
	}
}

func jsonStringRequired(payload map[string]interface{}, key string) string {
	v, ok := payload[key]
	if !ok || v == nil {
		log.Fatalf("config.json: missing required %q", key)
	}
	return jsonStringValue(v, key)
}

func jsonStringOptional(payload map[string]interface{}, key string, defaultVal string) string {
	v, ok := payload[key]
	if !ok || v == nil {
		return defaultVal
	}
	return jsonStringValue(v, key)
}

func jsonStringValue(v interface{}, key string) string {
	switch t := v.(type) {
	case string:
		return strings.TrimSpace(t)
	default:
		log.Fatalf("config.json: %q must be a string", key)
		return ""
	}
}

func jsonStringMapOptional(payload map[string]interface{}, key string) map[string]interface{} {
	v, ok := payload[key]
	if !ok || v == nil {
		return map[string]interface{}{}
	}
	m, ok := v.(map[string]interface{})
	if !ok {
		log.Fatalf("config.json: %q must be an object", key)
	}
	return m
}

// resolveTlsAddrFromJSON 未写 tls_addr → 默认 443；null 或 "" → 关闭 HTTPS。
func resolveTlsAddrFromJSON(payload map[string]interface{}) string {
	v, ok := payload["tls_addr"]
	if !ok {
		return "0.0.0.0:443"
	}
	if v == nil {
		return ""
	}
	return jsonStringValue(v, "tls_addr")
}

func applyEnvOverrides() {
	if v, ok := os.LookupEnv("REDI_HTTP_LISTEN"); ok {
		Addr = strings.TrimSpace(v)
	}
	if v, ok := os.LookupEnv("REDI_TLS_LISTEN"); ok {
		TlsAddr = strings.TrimSpace(v)
	}
	if v, ok := os.LookupEnv("REDI_API_LISTEN"); ok {
		ApiListen = strings.TrimSpace(v)
	}
}

func mergeLagranPortsFromListeners() {
	existing := lagranPortFromMap()
	Lagran["port"] = MergeListenerPorts(existing, Addr, TlsAddr)
}

func logStartupSummary() {
	log.Printf("[config] http=%s tls=%s lagran.ports=%s debug=%v",
		Addr, tlsListenLabel(), LagranSport(), Debug)
}

func tlsListenLabel() string {
	if strings.TrimSpace(TlsAddr) == "" {
		return "(disabled)"
	}
	return TlsAddr
}
