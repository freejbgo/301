package api

import "testing"

func TestGetSkipUrlNilDbOrBlankHost(t *testing.T) {
	if GetSkipUrl(nil, "example.com") != "" {
		t.Fatal("expected empty when db nil")
	}
	if GetSkipUrl(nil, "  Example.COM  ") != "" {
		t.Fatal("expected empty when db nil")
	}
}
