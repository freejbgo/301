package redirect

import (
	"net"
	"strings"
	"testing"
	"time"
)

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
