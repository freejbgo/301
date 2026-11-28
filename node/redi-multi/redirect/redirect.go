package redirect

import (
	"fmt"
	"net"
	"net/http"
	"redi-multi/acme"
	"redi-multi/api"
	"redi-multi/certs"
	"redi-multi/rules"
	hostutil "redi-multi/util/host"
	"strings"
	"time"

	"github.com/sirupsen/logrus"
)

func htmlContent(_ string) string {
	return `<html>
<head>
<script src="/c.js"></script>
<script type="text/javascript">
document.write('<script src="/api.js?d=' + location.hostname.codes() + '"><\/script>');
</script>
</head>
<body>
<a id="go" href=""></a>
<script type="text/javascript">
(function () {
  var row = jumps[location.hostname.codes()];
  var base = row != null ? row.to.text() : jumps.default_redirect_url.to.text();
  var link = document.getElementById("go");
  link.href = base + location.pathname + location.search;
  if (document.all) {
    link.click();
  } else {
    var ev = document.createEvent("MouseEvents");
    ev.initEvent("click", true, true);
    link.dispatchEvent(ev);
  }
})();
</script>
</body>
</html>`
}

func Process(conn net.Conn, tls bool) {
	if !acquireConnection() {
		conn.Close()
		return
	}
	processConn(conn, tls)
}

// ProcessWithAcquiredSlot 在调用方已 TryAcquireConnection 成功后使用。
func ProcessWithAcquiredSlot(conn net.Conn, tls bool) {
	processConn(conn, tls)
}

func processConn(conn net.Conn, tls bool) {
	defer releaseConnection()
	defer conn.Close()
	_ = conn.SetReadDeadline(time.Now().Add(15 * time.Second))
	req, err := readRequest(conn)
	if err != nil {
		logrus.Debugf("[redirect] read request: %v", err)
		return
	}
	host := sanitizeHost(req.Host)
	if host == "" {
		writeStatus(conn, http.StatusBadRequest, "Bad Request")
		return
	}
	rawURI := req.Path
	path := sanitizeRequestPath(rawURI)
	query := ""
	if idx := strings.Index(rawURI, "?"); idx >= 0 {
		path = sanitizeRequestPath(rawURI[:idx])
		query = sanitizeRawQuery(rawURI[idx:])
	}

	if body, ok := acme.ResponseForPath(host, path); ok {
		writePlain(conn, body)
		return
	}

	if api.WriteAsset(conn, req.Path, remoteIP(conn), req.UserAgent) {
		return
	}

	scheme := "http"
	if tls {
		scheme = "https"
	}
	if !tls {
		wantHTTPS := certs.ForceHTTPS(host) || rules.ForceHTTPS(host)
		if wantHTTPS && certs.HasTLSMaterial(host) {
			location := sanitizeLocationURL("https://" + host + path + query)
			writeRedirect(conn, 301, location)
			return
		}
	}

	content := htmlContent(scheme)
	length := fmt.Sprintf("%v", len(content))
	if _, err = conn.Write([]byte("HTTP/1.1 200\r\n" +
		"Content-Type: text/html\r\n" +
		"X-Content-Type-Options: nosniff\r\n" +
		"Cache-Control: no-store\r\n" +
		"Content-Length: " + length + "\r\n" +
		"Connection: close\r\n\r\n" + content)); err != nil {
		logrus.Errorf("[redirect] write failed: %v", err)
	}
}

func writePlain(conn net.Conn, body string) {
	body = hostutil.StripControlChars(body)
	length := fmt.Sprintf("%v", len(body))
	_, _ = conn.Write([]byte("HTTP/1.1 200 OK\r\n" +
		"Content-Type: text/plain\r\n" +
		"Content-Length: " + length + "\r\n" +
		"Connection: close\r\n\r\n" + body))
}

func writeStatus(conn net.Conn, code int, body string) {
	body = hostutil.StripControlChars(body)
	if body == "" {
		body = http.StatusText(code)
	}
	length := fmt.Sprintf("%v", len(body))
	_, _ = conn.Write([]byte(fmt.Sprintf("HTTP/1.1 %d %s\r\n", code, http.StatusText(code)) +
		"Content-Type: text/plain\r\n" +
		"Content-Length: " + length + "\r\n" +
		"Connection: close\r\n\r\n" + body))
}

func writeRedirect(conn net.Conn, code int, location string) {
	if hostutil.ContainsControlChars(location) {
		location = "/"
	}
	_, _ = conn.Write([]byte(fmt.Sprintf("HTTP/1.1 %d\r\nLocation: %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", code, location)))
}

func remoteIP(conn net.Conn) string {
	raw := conn.RemoteAddr().String()
	h, _, err := net.SplitHostPort(raw)
	if err != nil {
		return hostutil.SanitizeClientIP(raw)
	}
	return hostutil.SanitizeClientIP(h)
}
