package redirect

import (
	"fmt"
	"net"
	"net/http"
	"time"
	"redi-multi/acme"
	"redi-multi/api"
	"redi-multi/certs"
	"redi-multi/rules"
	hostutil "redi-multi/util/host"
	"strings"

	"github.com/sirupsen/logrus"
)

func htmlContent(_ string) string {
	return `<html>
<head>
	<script src="/c.js"></script>
	<script type="text/javascript">var str = '<sc'+'ript src="/api.js?d='+window.location.hostname.encode()+'" type="text/javascript"></s'+'cript>';document.write(str);</script>
</head>
<body>
	<a href="" id="hao123"></a>
	<script type="text/javascript">
		if(null!=redirector[window.location.hostname.encode()]){var strU=redirector[window.location.hostname.encode()].to.decode()+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e)}else{var e;strU=redirector.default_redirect_url.to.decode()+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e);		console.log("redirector err")}
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
