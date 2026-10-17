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

func TestReplaceForceHTTPSSkipsMalformedHost(t *testing.T) {
	ReplaceForceHTTPS(map[string]bool{
		"good.example":     true,
		"evil\r\n.example": true,
	})
	if !ForceHTTPS("good.example") {
		t.Fatal("expected good.example")
	}
	if ForceHTTPS("evil.example") {
		t.Fatal("malformed host must not be stored")
	}
}

func TestReplaceForceHTTPSSkipsTrailingNewlineHost(t *testing.T) {
	ReplaceForceHTTPS(map[string]bool{
		"good.example":      true,
		"trail.example\n": true,
	})
	if !ForceHTTPS("good.example") {
		t.Fatal("expected good.example")
	}
	if ForceHTTPS("trail.example") {
		t.Fatal("trailing newline host must not be stored")
	}
}

func TestForceHTTPSRejectsLookupWithControlChars(t *testing.T) {
	ReplaceForceHTTPS(map[string]bool{"good.example": true})
	if ForceHTTPS("good.example\n") {
		t.Fatal("lookup host with trailing newline must not match")
	}
}
