package api

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"redi-multi/config"
	"redi-multi/mysqlconnect"
	domain2 "redi-multi/util/domain"
	"redi-multi/util/host"
	"redi-multi/util/redirecturl"
	"strings"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/labstack/echo/v4"
	"github.com/sirupsen/logrus"
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
	// /api.js 在 handleApiJs 内经 allowApiJsDomainRate 计入全局 IP 限速；勿再挂 rateLimitMiddleware（会双计）。
	e.GET("/api.js", handleApiJs)

	listen := strings.TrimSpace(config.ApiListen)
	if listen == "" {
		listen = "127.0.0.1:8080"
	}
	if host.ContainsControlChars(listen) {
		e.Logger.Fatal("invalid api_listen: control characters")
	}
	if err := assertApiListenAllowed(listen); err != nil {
		e.Logger.Fatal(err)
	}
	e.Logger.Fatal(e.Start(listen))
}

func assertApiListenAllowed(listen string) error {
	hostPart, _, err := net.SplitHostPort(listen)
	if err != nil {
		return fmt.Errorf("invalid api_listen %q: %w", listen, err)
	}
	if strings.EqualFold(hostPart, "localhost") {
		hostPart = "127.0.0.1"
	}
	ip := net.ParseIP(hostPart)
	if ip == nil {
		return fmt.Errorf("invalid api_listen host %q", hostPart)
	}
	if ip.IsLoopback() {
		return nil
	}
	if strings.TrimSpace(os.Getenv("REDI_API_LISTEN_INSECURE")) == "1" {
		logrus.Warnf("[api] api_listen %q is not loopback; /api.js exposes DB-backed skip API", listen)
		return nil
	}
	return fmt.Errorf("api_listen %q must bind loopback only (set REDI_API_LISTEN_INSECURE=1 to override)", listen)
}

func setJSResponseHeaders(c echo.Context) {
	c.Response().Header().Set("Content-Type", "application/javascript; charset=utf-8")
}

func rateLimitMiddleware(next echo.HandlerFunc) echo.HandlerFunc {
	return func(c echo.Context) error {
		if !allowRate(host.SanitizeClientIP(c.RealIP())) {
			setJSResponseHeaders(c)
			return c.String(http.StatusTooManyRequests, "// rate limit")
		}
		return next(c)
	}
}

func allowRate(ip string) bool {
	ip = host.SanitizeClientIP(ip)
	if ip == "" {
		return false
	}
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
	setJSResponseHeaders(c)
	return c.String(http.StatusOK, cjsSource(host.SanitizeClientIP(c.RealIP()), host.SanitizeUserAgent(c.Request().UserAgent())))
}

func handleApiJs(c echo.Context) error {
	encoded := c.QueryParam("d")
	if !apiJsQueryValid(encoded) {
		setJSResponseHeaders(c)
		return c.String(http.StatusBadRequest, "// invalid")
	}
	if !allowApiJsDomainRate(c.RealIP(), encoded) {
		setJSResponseHeaders(c)
		return c.String(http.StatusTooManyRequests, "// rate limit")
	}
	body, code := apiJsSource(encoded)
	setJSResponseHeaders(c)
	return c.String(code, body)
}

func apiJsQueryValid(encoded string) bool {
	if strings.TrimSpace(encoded) == "" {
		return false
	}
	return len(encoded) <= 4096 && !host.ContainsControlChars(encoded)
}

func allowApiJsDomainRate(ip, encodedDomain string) bool {
	ip = host.SanitizeClientIP(ip)
	if ip == "" {
		return false
	}
	key := ip + "|" + strings.TrimSpace(encodedDomain)
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
	visit, _ := json.Marshal(map[string]string{
		"cip": clientIP,
		"ua":  userAgent,
	})
	return fmt.Sprintf("var visit = %s;", string(visit)) + `
String.prototype.codes = function () {
	var bytes = [];
	for (var i = 0; i < this.length; i++) {
		bytes.push(this.charCodeAt(i));
	}
	return bytes.join(",");
};
String.prototype.text = function () {
	return String.fromCharCode.apply(null, this.trim().split(","));
};
`
}

func apiJsSource(encodedDomain string) (string, int) {
	if !apiJsQueryValid(encodedDomain) {
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
	return "var jumps = " + string(payload) + ";", http.StatusOK
}

func GetSkipUrl(Db *sqlx.DB, Url string) string {
	if Db == nil {
		return ""
	}
	hostKey := host.AcmeHostKey(Url)
	if hostKey == "" {
		return ""
	}

	run := func(db *sqlx.DB) (sql.NullString, error) {
		rows, err := db.Query("CALL redi_node_bump_skip(?)", hostKey)
		if err != nil {
			return sql.NullString{}, err
		}
		defer rows.Close()
		var skip sql.NullString
		if rows.Next() {
			if err := rows.Scan(&skip); err != nil {
				return sql.NullString{}, err
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
		if skip.Valid {
			return skip.String
		}
		return ""
	}
	if mysqlconnect.Reconnect() == nil {
		if fresh := mysqlconnect.DB(); fresh != nil {
			skip, err = run(fresh)
			if err == nil {
				if skip.Valid {
					return skip.String
				}
				return ""
			}
		}
	}
	logrus.Warnf("[api.js] redi_node_bump_skip failed for host %q", hostKey)
	return ""
}
