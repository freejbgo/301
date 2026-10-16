package config

import "testing"

func TestResolveTlsAddrFromJSON(t *testing.T) {
	a := resolveTlsAddrFromJSON(map[string]interface{}{})
	if a != "0.0.0.0:443" {
		t.Fatalf("absent: %q", a)
	}
	a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": ""})
	if a != "" {
		t.Fatalf("empty: %q", a)
	}
	a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": nil})
	if a != "" {
		t.Fatalf("null: %q", a)
	}
	a = resolveTlsAddrFromJSON(map[string]interface{}{"tls_addr": "0.0.0.0:8443"})
	if a != "0.0.0.0:8443" {
		t.Fatalf("custom: %q", a)
	}
}

func TestScalarToPortString(t *testing.T) {
	if scalarToPortString(float64(443)) != "443" {
		t.Fatal("float port")
	}
	if scalarToPortString("80,443") != "80,443" {
		t.Fatal("string port list")
	}
}

func TestNormalizeLagranPortNumber(t *testing.T) {
	Lagran = map[string]interface{}{"port": float64(80)}
	normalizeLagranMap()
	if Lagran["port"] != "80" {
		t.Fatalf("got %v", Lagran["port"])
	}
}

func TestScalarToBoolString(t *testing.T) {
	if !scalarToBool("yes", false) {
		t.Fatal("yes")
	}
	if scalarToBool("off", true) {
		t.Fatal("off")
	}
}

func TestJsonBoolDefault(t *testing.T) {
	if jsonBool(map[string]interface{}{}, "debug", true) != true {
		t.Fatal("expected default true")
	}
}
