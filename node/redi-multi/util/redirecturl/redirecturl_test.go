package redirecturl

import "testing"

func TestIsAllowed(t *testing.T) {
	if !IsAllowed("https://example.com/path") {
		t.Fatal("expected https URL allowed")
	}
	if IsAllowed("javascript:alert(1)") {
		t.Fatal("expected javascript rejected")
	}
	if IsAllowed("https://user:pass@example.com") {
		t.Fatal("expected userinfo rejected")
	}
	if IsAllowed("https://evil.com\r\n") {
		t.Fatal("expected control chars rejected")
	}
}
