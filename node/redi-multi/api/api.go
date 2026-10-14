package api

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"strings"
	"redi-multi/util/redirecturl"
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
	rateMu          sync.Mutex
	rateHits        = map[string][]time.Time{}
	rateLimit       = 120 // 每 IP 每分钟最大请求数（/c.js、/api.js 共用）
	domainRateHits  = map[string][]time.Time{}
	domainRateLimit = 60 // 每 IP+域名参数 每分钟（仅 /api.js，减轻刷 skip_num）
)

func Start() {
	e := echo.New()
	e.HideBanner = true
	e.IPExtractor = echo.ExtractIPDirect()
	e.GET("/c.js", handleCjs, rateLimitMiddleware)
	e.GET("/api.js", handleApiJs, rateLimitMiddleware)

	listen := os.Getenv("REDI_API_LISTEN")
	if listen == "" {
		listen = config.ApiListen
	}
	if listen == "" {
		listen = "127.0.0.1:8080"
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
		if len(kept) == 0 {
			delete(rateHits, ip)
		} else {
			rateHits[ip] = kept
		}
		return false
	}
	kept = append(kept, now)
	rateHits[ip] = kept
	if len(rateHits) > 4096 {
		pruneRateMap(cutoff)
	}
	return true
}

func pruneRateMap(cutoff time.Time) {
	for ip, hits := range rateHits {
		var kept []time.Time
		for _, t := range hits {
			if t.After(cutoff) {
				kept = append(kept, t)
			}
		}
		if len(kept) == 0 {
			delete(rateHits, ip)
		} else {
			rateHits[ip] = kept
		}
	}
}

func handleCjs(c echo.Context) error {
	c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
	return c.String(http.StatusOK, cjsSource(c.RealIP(), c.Request().UserAgent()))
}

func handleApiJs(c echo.Context) error {
	encoded := c.QueryParam("d")
	if !allowApiJsDomainRate(c.RealIP(), encoded) {
		return c.String(http.StatusTooManyRequests, "// rate limit")
	}
	body, code := apiJsSource(encoded)
	c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
	return c.String(code, body)
}

func allowApiJsDomainRate(ip, encodedDomain string) bool {
	if len(encodedDomain) > 4096 {
		return false
	}
	key := ip + "|" + strings.TrimSpace(encodedDomain)
	if key != ip+"|" {
		now := time.Now()
		cutoff := now.Add(-1 * time.Minute)
		rateMu.Lock()
		hits := domainRateHits[key]
		var kept []time.Time
		for _, t := range hits {
			if t.After(cutoff) {
				kept = append(kept, t)
			}
		}
		if len(kept) >= domainRateLimit {
			domainRateHits[key] = kept
			rateMu.Unlock()
			return false
		}
		kept = append(kept, now)
		domainRateHits[key] = kept
		if len(domainRateHits) > 8192 {
			pruneDomainRateMap(cutoff)
		}
		rateMu.Unlock()
	}
	return allowRate(ip)
}

func pruneDomainRateMap(cutoff time.Time) {
	for k, hits := range domainRateHits {
		var kept []time.Time
		for _, t := range hits {
			if t.After(cutoff) {
				kept = append(kept, t)
			}
		}
		if len(kept) == 0 {
			delete(domainRateHits, k)
		} else {
			domainRateHits[k] = kept
		}
	}
}

func cjsSource(clientIP, userAgent string) string {
	cinfo, _ := json.Marshal(map[string]string{
		"cip": clientIP,
		"ua":  userAgent,
	})
	return fmt.Sprintf("var cinfo = %s;", string(cinfo)) + `
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
}

func apiJsSource(encodedDomain string) (string, int) {
	if len(encodedDomain) > 4096 {
		return "// invalid", http.StatusBadRequest
	}
	db := mysqlconnect.DB()
	if db == nil {
		return "// database unavailable", http.StatusServiceUnavailable
	}
	domain := domain2.Encoded{Content: encodedDomain}
	skipUrl := GetSkipUrl(db, domain.Decode())
	if skipUrl != "" && !redirecturl.IsAllowed(skipUrl) {
		skipUrl = ""
	}

	var body map[string]map[string]string
	if skipUrl != "" {
		decodeTo := domain2.Decoded{Content: skipUrl}
		body = map[string]map[string]string{
			encodedDomain: {"to": decodeTo.Encode(), "uid": "0"},
		}
	} else {
		fallback := config.DefaultRedirectUrl
		if !redirecturl.IsAllowed(fallback) {
			return "// invalid default redirect", http.StatusInternalServerError
		}
		decodeTo := domain2.Decoded{Content: fallback}
		body = map[string]map[string]string{
			"default_redirect_url": {"to": decodeTo.Encode(), "uid": "0"},
		}
	}
	payload, err := json.Marshal(body)
	if err != nil {
		return "// error", http.StatusInternalServerError
	}
	return "var redirector = " + string(payload) + ";", http.StatusOK
}

func GetSkipUrl(Db *sqlx.DB, Url string) string {
	if Db == nil {
		return ""
	}
	Url = strings.ToLower(strings.TrimSpace(Url))
	if Url == "" {
		return ""
	}

	run := func(db *sqlx.DB) (string, error) {
		rows, err := db.Query("CALL redi_node_bump_skip(?)", Url)
		if err != nil {
			return "", err
		}
		defer rows.Close()
		var skip string
		if rows.Next() {
			if err := rows.Scan(&skip); err != nil {
				return "", err
			}
		}
		for rows.NextResultSet() {
			for rows.Next() {
			}
		}
		return skip, nil
	}

	skip, err := run(Db)
	if err == nil {
		return skip
	}
	if mysqlconnect.Reconnect() == nil {
		if fresh := mysqlconnect.DB(); fresh != nil {
			skip, err = run(fresh)
			if err == nil {
				return skip
			}
		}
	}
	return ""
}
