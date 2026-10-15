package rules

import "testing"

func TestForceHTTPS(t *testing.T) {
	ReplaceForceHTTPS(map[string]bool{
		"Foo.Example": true,
		"plain.example": false,
	})
	if !ForceHTTPS("foo.example") {
		t.Fatal("expected force https for foo.example")
	}
	if ForceHTTPS("plain.example") {
		t.Fatal("expected no force for plain.example")
	}
	if ForceHTTPS("unknown.example") {
		t.Fatal("expected false for unknown host")
	}
}
