package config

import "testing"

func TestResolveTlsAddrFromJSON(t *testing.T) {
	_, a := resolveTlsAddrFromJSON(map[string]interface{}{})
	if a != "0.0.0.0:443" {
		t.Fatalf("absent: %q", a)
	}
	_, a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": ""})
	if a != "" {
		t.Fatalf("empty: %q", a)
	}
	_, a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": nil})
	if a != "" {
		t.Fatalf("null: %q", a)
	}
	_, a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": "0.0.0.0:8443"})
	if a != "0.0.0.0:8443" {
		t.Fatalf("custom: %q", a)
	}
}

func TestJsonBoolDefault(t *testing.T) {
	if jsonBool(map[string]interface{}{}, "debug", true) != true {
		t.Fatal("expected default true")
	}
}
