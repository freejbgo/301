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

func TestApiJsSourceRejectsControlChars(t *testing.T) {
	body, code := apiJsSource("49,50\n")
	if code != http.StatusBadRequest || body != "// invalid" {
		t.Fatalf("expected 400 invalid, got %d %q", code, body)
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
