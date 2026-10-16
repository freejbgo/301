package host

import "testing"

func TestCanonicalLookupHostPunycode(t *testing.T) {
	got := CanonicalLookupHost("münchen.de")
	if got == "" || got == "münchen.de" {
		t.Fatalf("expected punycode ascii, got %q", got)
	}
	if got[:4] != "xn--" {
		t.Fatalf("expected xn-- prefix, got %q", got)
	}
}

func TestCanonicalLookupHostPlain(t *testing.T) {
	if CanonicalLookupHost("Foo.COM") != "foo.com" {
		t.Fatal("expected lower ascii")
	}
}

func TestCanonicalLookupHostRejectsCRLF(t *testing.T) {
	if CanonicalLookupHost("evil.com\r\n") != "" {
		t.Fatal("expected empty for CRLF host")
	}
}

func TestCanonicalLookupHostRejectsTab(t *testing.T) {
	if CanonicalLookupHost("evil\t.com") != "" {
		t.Fatal("expected empty for tab in host")
	}
}
