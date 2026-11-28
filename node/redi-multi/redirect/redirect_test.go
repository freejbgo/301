package redirect

import (
	"net"
	"strings"
	"testing"
	"time"
)

func TestJumpPageUsesNeutralMarkers(t *testing.T) {
	page := htmlContent("example.com")
	for _, banned := range []string{"hao123", "redirector", ".encode()", ".decode()", "ActiveXObject"} {
		if strings.Contains(page, banned) {
			t.Fatalf("page still contains %q", banned)
		}
	}
	for _, need := range []string{`id="go"`, "jumps[", ".codes()", ".text()", `src="/c.js"`, `src="/api.js?d=`} {
		if !strings.Contains(page, need) {
			t.Fatalf("page missing %q", need)
		}
	}
}

func TestWritePlainStripsControlCharsFromBody(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	go func() {
		writePlain(server, "token\r\nsuffix")
	}()
	buf := make([]byte, 256)
	_ = client.SetReadDeadline(time.Now().Add(2 * time.Second))
	n, _ := client.Read(buf)
	body := string(buf[:n])
	if strings.Contains(body, "tokensuffix") && !strings.Contains(body, "\r\n\r\n") {
		// body after headers should be tokensuffix
		if !strings.HasSuffix(body, "tokensuffix") {
			t.Fatalf("expected stripped acme body, got %q", body)
		}
	}
	if strings.Contains(body, "token\r\n") {
		t.Fatal("control chars should be stripped from plain body")
	}
}

func TestWriteRedirectSanitizesLocationControlChars(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	go func() {
		writeRedirect(server, 301, "https://evil.com\r\nInjected: header")
	}()
	buf := make([]byte, 512)
	_ = client.SetReadDeadline(time.Now().Add(2 * time.Second))
	n, _ := client.Read(buf)
	body := string(buf[:n])
	if strings.Contains(body, "Injected:") {
		t.Fatalf("unexpected injected header in response: %q", body)
	}
	if !strings.Contains(body, "Location: /") {
		t.Fatalf("expected fallback location, got %q", body)
	}
}
