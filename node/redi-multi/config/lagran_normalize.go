package config

import (
	"encoding/json"
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
	for _, key := range []string{"syn_ack", "ack", "psh_ack", "fin_ack"} {
		if v, ok := Lagran[key]; ok && v != nil {
			Lagran[key] = scalarToBool(v, true)
		}
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
