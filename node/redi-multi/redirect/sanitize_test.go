package redirect

import "testing"

func TestSanitizeRequestPathBlocksProtocolRelative(t *testing.T) {
	if p := sanitizeRequestPath("//evil.com/x"); p != "/" {
		t.Fatalf("expected /, got %q", p)
	}
}

func TestSanitizeLocationURLStripsCRLF(t *testing.T) {
	if loc := sanitizeLocationURL("https://a.com\r\nX: y"); loc != "/" {
		t.Fatalf("expected /, got %q", loc)
	}
}
