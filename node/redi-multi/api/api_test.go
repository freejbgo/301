package api

import (
	"net/http"
	"testing"
)

func TestGetSkipUrlNilDbOrBlankHost(t *testing.T) {
	if GetSkipUrl(nil, "example.com") != "" {
		t.Fatal("expected empty when db nil")
	}
	if GetSkipUrl(nil, "  Example.COM  ") != "" {
		t.Fatal("expected empty when db nil")
	}
}

func TestGetSkipUrlRejectsInvalidHostWithoutDb(t *testing.T) {
	if GetSkipUrl(nil, "not a host!!!") != "" {
		t.Fatal("expected empty for invalid host label")
	}
	if GetSkipUrl(nil, "1.2.3.4") != "" {
		t.Fatal("expected empty for IPv4 literal")
	}
	if GetSkipUrl(nil, "https://evil.com/path") != "" {
		t.Fatal("expected empty for host with path")
	}
}

func TestApiJsSourceRejectsControlChars(t *testing.T) {
	body, code := apiJsSource("49,50\n")
	if code != http.StatusBadRequest || body != "// invalid" {
		t.Fatalf("expected 400 invalid, got %d %q", code, body)
	}
}

func TestAllowRateRejectsControlCharIP(t *testing.T) {
	if allowRate("203.0.113.1\n") {
		t.Fatal("expected rate limit deny for invalid IP")
	}
}

func TestApiJsQueryValid(t *testing.T) {
	if !apiJsQueryValid("") {
		t.Fatal("empty query should be valid")
	}
	if apiJsQueryValid("x\n") {
		t.Fatal("control chars should be invalid")
	}
}
