package api

import (
	"net"
	"strings"
	"testing"
	"time"
)

func TestWriteAssetRejectsControlCharsInURI(t *testing.T) {
	if WriteAsset(nil, "/c.js\n", "1.2.3.4", "ua") {
		t.Fatal("expected false for control chars in URI")
	}
}

func TestWriteAssetCjsRateLimitOn80Path(t *testing.T) {
	rateMu.Lock()
	rateHits = map[string][]time.Time{}
	rateMu.Unlock()

	ip := "203.0.113.55"
	for i := 0; i < rateLimit; i++ {
		if !writeAssetCjsOnce(ip) {
			t.Fatalf("request %d: expected c.js served", i)
		}
	}
	if writeAssetCjsOnce(ip) {
		t.Fatal("expected rate limit after burst")
	}
}

func writeAssetCjsOnce(ip string) bool {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()

	go func() {
		_ = WriteAsset(server, "/c.js", ip, "test")
	}()

	var buf strings.Builder
	_ = client.SetReadDeadline(time.Now().Add(2 * time.Second))
	tmp := make([]byte, 4096)
	for {
		n, err := client.Read(tmp)
		if n > 0 {
			buf.Write(tmp[:n])
		}
		if err != nil {
			break
		}
		if strings.Contains(buf.String(), "\r\n\r\n") {
			break
		}
	}
	body := buf.String()
	if strings.Contains(body, "429") {
		return false
	}
	return strings.Contains(body, "200 OK") && strings.Contains(body, "var cinfo")
}
