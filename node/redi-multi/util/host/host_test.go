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
