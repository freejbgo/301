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

func TestSanitizeHostRejectsTab(t *testing.T) {
	if h := sanitizeHost("evil\t.com"); h != "" {
		t.Fatalf("expected empty host, got %q", h)
	}
}

func TestSanitizeHostCanonicalizesAscii(t *testing.T) {
	if h := sanitizeHost("Foo.COM"); h != "foo.com" {
		t.Fatalf("expected foo.com, got %q", h)
	}
}
