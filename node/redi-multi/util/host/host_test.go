package host

import (
	"strings"
	"testing"
)

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

func TestAcmeHostKeyStripsScheme(t *testing.T) {
	if AcmeHostKey("https://Foo.COM/") != "foo.com" {
		t.Fatal("expected scheme stripped host key")
	}
	if AcmeHostKey("not a host!!!") != "" {
		t.Fatal("expected empty for invalid host")
	}
	if AcmeHostKey("1.2.3.4") != "" {
		t.Fatal("expected empty for IPv4 literal (aligned with HostNameValidator)")
	}
}

func TestCanonicalLookupHostRejectsCRLF(t *testing.T) {
	if CanonicalLookupHost("evil.com\r\n") != "" {
		t.Fatal("expected empty for CRLF host")
	}
}

func TestContainsControlChars(t *testing.T) {
	if !ContainsControlChars("a\n") {
		t.Fatal("expected newline to be control char")
	}
	if ContainsControlChars("abc") {
		t.Fatal("expected plain ascii to be clean")
	}
}

func TestCanonicalLookupHostRejectsTab(t *testing.T) {
	if CanonicalLookupHost("evil\t.com") != "" {
		t.Fatal("expected empty for tab in host")
	}
}

func TestStripControlChars(t *testing.T) {
	if StripControlChars("a\r\nb") != "ab" {
		t.Fatal("expected strip")
	}
	if StripControlChars("plain") != "plain" {
		t.Fatal("expected unchanged")
	}
}

func TestLogSnippet(t *testing.T) {
	if LogSnippet("a\r\nb", 10) != "ab" {
		t.Fatal("expected trimmed snippet")
	}
	if len(LogSnippet(strings.Repeat("z", 600), 500)) != 500 {
		t.Fatal("expected truncate")
	}
}

func TestSanitizeClientIP(t *testing.T) {
	if SanitizeClientIP("203.0.113.1\n") != "" {
		t.Fatal("expected empty for CRLF in IP")
	}
	if SanitizeClientIP(" 10.0.0.1 ") != "10.0.0.1" {
		t.Fatal("expected trim")
	}
	if SanitizeClientIP("::ffff:203.0.113.5") != "203.0.113.5" {
		t.Fatalf("expected IPv4-mapped form normalized, got %q", SanitizeClientIP("::ffff:203.0.113.5"))
	}
	if SanitizeClientIP("0:0:0:0:0:ffff:203.0.113.5") != "203.0.113.5" {
		t.Fatalf("expected long mapped form normalized, got %q", SanitizeClientIP("0:0:0:0:0:ffff:203.0.113.5"))
	}
	if SanitizeClientIP("2001:db8::1") != "2001:db8::1" {
		t.Fatalf("expected IPv6 unchanged, got %q", SanitizeClientIP("2001:db8::1"))
	}
	if SanitizeClientIP(" 2001:0db8::1 ") != "2001:0db8::1" {
		t.Fatalf("expected trimmed IPv6 literal preserved, got %q", SanitizeClientIP(" 2001:0db8::1 "))
	}
}

func TestSanitizeUserAgent(t *testing.T) {
	if SanitizeUserAgent("Mozilla/5.0\r\n") != "" {
		t.Fatal("expected empty for CRLF in UA")
	}
	long := strings.Repeat("a", 600)
	if len(SanitizeUserAgent(long)) != 512 {
		t.Fatal("expected UA truncated to 512")
	}
}
