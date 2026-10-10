package redirect

import (
	"fmt"
	"net"
	"redi-multi/acme"
	"redi-multi/certs"
	"redi-multi/config"
	"redi-multi/rules"
	"strings"

	"github.com/sirupsen/logrus"
)

func htmlContent(scheme string) string {
	apiHost := config.ServerIp
	if scheme == "https" {
		return fmt.Sprintf(`<html>
<head>
	<script src="https://%s:8080/c.js"></script>
	<script type="text/javascript">var str = '<sc'+'ript src="https://%s:8080/api.js?d='+window.location.hostname.encode()+'" type="text/javascript"></s'+'cript>';document.write(str);</script>
</head>
<body>
	<a href="" id="hao123"></a>
	<script type="text/javascript">
		if(null!=redirector[window.location.hostname.encode()]){var strU=redirector[window.location.hostname.encode()].to.decode()+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e)}else{var e;strU=redirector.default_redirect_url.to+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e);console.log("redirector err")}
	</script>
</body>
</html>`, apiHost, apiHost)
	}
	return fmt.Sprintf(`<html>
<head>
	<script src="http://%s:8080/c.js"></script>
	<script type="text/javascript">var str = '<sc'+'ript src="http://%s:8080/api.js?d='+window.location.hostname.encode()+'" type="text/javascript"></s'+'cript>';document.write(str);</script>
</head>
<body>
	<a href="" id="hao123"></a>
	<script type="text/javascript">
		if(null!=redirector[window.location.hostname.encode()]){var strU=redirector[window.location.hostname.encode()].to.decode()+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e)}else{var e;strU=redirector.default_redirect_url.to+window.location.pathname+window.location.search;if(hao123.href=strU,document.all)document.getElementById("hao123").click();else(e=document.createEvent("MouseEvents")).initEvent("click",!0,!0),document.getElementById("hao123").dispatchEvent(e);console.log("redirector err")}
	</script>
</body>
</html>`, apiHost, apiHost)
}

func Process(conn net.Conn, tls bool) {
	defer conn.Close()
	req, err := readRequest(conn)
	if err != nil {
		logrus.Debugf("[redirect] read request: %v", err)
		return
	}
	host := strings.ToLower(req.Host)
	path := req.Path
	if idx := strings.Index(path, "?"); idx >= 0 {
		path = path[:idx]
	}

	if body, ok := acme.ResponseForPath(host, path); ok {
		writePlain(conn, body)
		return
	}

	scheme := "http"
	if tls {
		scheme = "https"
	}
	if !tls && (certs.ForceHTTPS(host) || rules.ForceHTTPS(host)) {
		location := "https://" + host + req.Path
		writeRedirect(conn, 301, location)
		return
	}

	content := htmlContent(scheme)
	length := fmt.Sprintf("%v", len(content))
	if _, err = conn.Write([]byte("HTTP/1.1 200\r\n" +
		"Content-Type: text/html\r\n" +
		"Cache-Control: no-store\r\n" +
		"Content-Length: " + length + "\r\n" +
		"Connection: close\r\n\r\n" + content)); err != nil {
		logrus.Errorf("[redirect] write failed: %v", err)
	}
}

func writePlain(conn net.Conn, body string) {
	length := fmt.Sprintf("%v", len(body))
	_, _ = conn.Write([]byte("HTTP/1.1 200 OK\r\n" +
		"Content-Type: text/plain\r\n" +
		"Content-Length: " + length + "\r\n" +
		"Connection: close\r\n\r\n" + body))
}

func writeRedirect(conn net.Conn, code int, location string) {
	_, _ = conn.Write([]byte(fmt.Sprintf("HTTP/1.1 %d\r\nLocation: %s\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", code, location)))
}
