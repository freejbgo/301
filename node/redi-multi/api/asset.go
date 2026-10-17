package api

import (
	"fmt"
	"net"
	"net/url"

	"redi-multi/util/host"
)

// WriteAsset 在 80/443 上直接输出 /c.js 与 /api.js，使 HTTPS 页面用当前域名加载脚本。
func WriteAsset(conn net.Conn, requestURI, remoteIP, userAgent string) bool {
	if host.ContainsControlChars(requestURI) {
		return false
	}
	u, err := url.ParseRequestURI(requestURI)
	if err != nil || u.Host != "" {
		return false
	}
	switch u.Path {
	case "/c.js":
		if !allowRate(remoteIP) {
			writeJS(conn, httpStatusLine(429), "// rate limit")
			return true
		}
		writeJS(conn, httpStatusLine(200), cjsSource(host.SanitizeClientIP(remoteIP), host.SanitizeUserAgent(userAgent)))
		return true
	case "/api.js":
		encoded := u.Query().Get("d")
		if !apiJsQueryValid(encoded) {
			writeJS(conn, httpStatusLine(400), "// invalid")
			return true
		}
		if !allowApiJsDomainRate(remoteIP, encoded) {
			writeJS(conn, httpStatusLine(429), "// rate limit")
			return true
		}
		body, code := apiJsSource(encoded)
		writeJS(conn, httpStatusLine(code), body)
		return true
	default:
		return false
	}
}

func httpStatusLine(code int) string {
	switch code {
	case 200:
		return "HTTP/1.1 200 OK"
	case 400:
		return "HTTP/1.1 400 Bad Request"
	case 429:
		return "HTTP/1.1 429 Too Many Requests"
	default:
		return fmt.Sprintf("HTTP/1.1 %d", code)
	}
}

func writeJS(conn net.Conn, statusLine, body string) {
	_, _ = conn.Write([]byte(statusLine + "\r\n" +
		"Content-Type: application/javascript; charset=utf-8\r\n" +
		"X-Content-Type-Options: nosniff\r\n" +
		"Cache-Control: no-store\r\n" +
		"Content-Length: " + fmt.Sprintf("%d", len(body)) + "\r\n" +
		"Connection: close\r\n\r\n" + body))
}
