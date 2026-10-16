package redirect

import "testing"

func TestSanitizeRequestPathBlocksProtocolRelative(t *testing.T) {
	if p := sanitizeRequestPath("//evil.com/x"); p != "/" {
		t.Fatalf("expected /, got %q", p)
	}
}

func TestSanitizeRequestPathBlocksBackslash(t *testing.T) {
	if p := sanitizeRequestPath("/admin\\evil"); p != "/" {
		t.Fatalf("expected /, got %q", p)
	}
}

func TestSanitizeRawQueryPreservesQuery(t *testing.T) {
	if q := sanitizeRawQuery("?a=1&b=2"); q != "?a=1&b=2" {
		t.Fatalf("expected query, got %q", q)
	}
}

func TestSanitizeLocationURLStripsCRLF(t *testing.T) {
	if loc := sanitizeLocationURL("https://a.com\r\nX: y"); loc != "/" {
		t.Fatalf("expected /, got %q", loc)
	}
}

func TestSanitizeHostStripsCRLF(t *testing.T) {
	if h := sanitizeHost("evil.com\r\n"); h != "" {
		t.Fatalf("expected empty host, got %q", h)
	}
}
