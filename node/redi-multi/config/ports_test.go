package config

import "testing"

func TestPortFromListenAddr(t *testing.T) {
	cases := map[string]string{
		"0.0.0.0:80":   "80",
		"0.0.0.0:443":  "443",
		"[::]:443":     "443",
		"::80":         "80",
		"::443":        "443",
		":8080":        "8080",
		"":             "",
	}
	for in, want := range cases {
		if got := portFromListenAddr(in); got != want {
			t.Fatalf("%q => %q, want %q", in, got, want)
		}
	}
}

func TestMergeListenerPortsNoTls(t *testing.T) {
	got := MergeListenerPorts("80", "0.0.0.0:80", "")
	if got != "80" {
		t.Fatalf("got %q", got)
	}
}
