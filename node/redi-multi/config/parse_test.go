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

func TestLagranWindowPerPort(t *testing.T) {
	Lagran = map[string]interface{}{
		"port": "80,443,8080",
		"window": map[string]interface{}{
			"080": "2",
			"443": float64(1024),
		},
	}
	if err := applyLagranWindows(); err != nil {
		t.Fatal(err)
	}
	if LagranWindow("80") != 2 {
		t.Fatalf("canonical port 80 window %d", LagranWindow("80"))
	}
	if LagranWindow("443") != 1024 {
		t.Fatalf("port 443 window %d", LagranWindow("443"))
	}
	if LagranWindow("8080") != defaultLagranWindow {
		t.Fatalf("unset port window %d", LagranWindow("8080"))
	}
	if LagranWindowSummary() != "80=2,443=1024,8080=1" {
		t.Fatalf("summary %q", LagranWindowSummary())
	}
}

func TestLagranWindowRejectsZero(t *testing.T) {
	Lagran = map[string]interface{}{
		"window": map[string]interface{}{"80": float64(0)},
	}
	if err := applyLagranWindows(); err == nil {
		t.Fatal("expected window 0 to fail")
	}
	Lagran = map[string]interface{}{
		"window": map[string]interface{}{"443": float64(1.5)},
	}
	if err := applyLagranWindows(); err == nil {
		t.Fatal("expected fractional window to fail")
	}
}

func TestJsonBoolDefault(t *testing.T) {
	if jsonBool(map[string]interface{}{}, "debug", true) != true {
		t.Fatal("expected default true")
	}
}
