package api

import (
	"fmt"
	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/labstack/echo/v4"
	"net/http"
	"redi-multi/config"
	"redi-multi/mysqlconnect"
	domain2 "redi-multi/util/domain"
	"strings"
)

func Start() {
	e := echo.New()
	e.HideBanner = true
	e.GET("/c.js", func(c echo.Context) error {
		remoteAddr := c.Request().RemoteAddr
		ip := strings.Split(remoteAddr, ":")[0]
		response := fmt.Sprintf("var cinfo = {\"cip\": \"%s\", \"ua\": \"%s\"};", ip, c.Request().UserAgent())
		response += `
function createXHR () {
var XHR = [  //兼容不同浏览器和版本得创建函数数组
	function () { return new XMLHttpRequest () },
	function () { return new ActiveXObject ("Msxml2.XMLHTTP") },
	function () { return new ActiveXObject ("Msxml3.XMLHTTP") },
	function () { return new ActiveXObject ("Microsoft.XMLHTTP") }
];
var xhr = null;
//尝试调用函数，如果成功则返回XMLHttpRequest对象，否则继续尝试
for (var i = 0; i < XHR.length; i ++) {
	try {
		xhr = XHR[i]();
	} catch(e) {
		continue  //如果发生异常，则继续下一个函数调用
	}
		break;  //如果成功，则中止循环
	}
	return xhr;  //返回对象实例
}
String.prototype.encode = function () {
	var bytes = [];
	for (var i = 0; i < this.length; i++) {
		bytes.push(this.charCodeAt(i));
	}
	return bytes.join(',');
}
String.prototype.decode = function () {
	return String.fromCharCode.apply(null,this.trim().split(","));
}
`
		c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
		return c.String(http.StatusOK, response)
	})

	e.GET("/api.js", func(c echo.Context) error {
		var Db *sqlx.DB = mysqlconnect.ConnectMysql()

		encodedDomain := c.QueryParam("d")
		domain := domain2.Encoded{Content: encodedDomain}
		var SkipUrl = GetSkipUrl(Db, domain.Decode())
		var res string
		if SkipUrl != "" {
			decodeTo := domain2.Decoded{Content: SkipUrl}
			res = fmt.Sprintf(`var redirector = {"%s": {"to": "%s", "uid": "%v"}};`, encodedDomain, decodeTo.Encode(), "0")
		} else {
			res = fmt.Sprintf(`var redirector = {"%s": {"to": "%s", "uid": "%v"}};`, "default_redirect_url", config.DefaultRedirectUrl, "0")
		}
		c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
		return c.String(http.StatusOK, res)

	})

	e.Logger.Fatal(e.Start(":8080"))
}

func GetSkipUrl(Db *sqlx.DB, Url string) string {

	type domainInfo struct {
		Id          int    `db:"id"`
		GroupId     int    `db:"group_id"`
		OriginalUrl string `db:"original_url"`
		SkipUrl     string `db:"skip_url"`
		SkipNum     string `db:"skip_num"`
		Status      int    `db:"status"`
		AdminId     int    `db:"admin_id"`
		AdminName   string `db:"admin_name"`
		CreateTime  string `db:"created_at"`
		UpdateTime  string `db:"updated_at"`
	}

	//初始化定义结构体，用来存放查询数据
	var domainData *domainInfo = new(domainInfo)
	err := Db.Get(domainData, "select * from redi_admin_domain where original_url = ? and status = 1", Url)
	if err != nil {
		return ""
	}

	Db.Exec("update redi_admin_domain set skip_num=skip_num+1 where original_url = ? and status = 1", Url)

	return domainData.SkipUrl
}
