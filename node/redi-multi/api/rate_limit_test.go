package api

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/labstack/echo/v4"
)

func TestHandleApiJsEchoPathSingleGlobalRateCount(t *testing.T) {
	rateMu.Lock()
	rateHits = map[string][]time.Time{}
	domainRateHits = map[string][]time.Time{}
	rateMu.Unlock()

	e := echo.New()
	e.GET("/api.js", handleApiJs)

	ip := "203.0.113.21"
	for i := 0; i < rateLimit; i++ {
		req := httptest.NewRequest(http.MethodGet, "/api.js", nil)
		req.RemoteAddr = ip + ":12345"
		rec := httptest.NewRecorder()
		e.ServeHTTP(rec, req)
		if rec.Code == http.StatusTooManyRequests {
			t.Fatalf("request %d: unexpected 429 before global limit", i)
		}
	}
	req := httptest.NewRequest(http.MethodGet, "/api.js", nil)
	req.RemoteAddr = ip + ":12345"
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusTooManyRequests {
		t.Fatalf("expected 429 after %d requests, got %d", rateLimit, rec.Code)
	}
}

func TestHandleApiJsDomainRateLimitSeparateFromGlobal(t *testing.T) {
	rateMu.Lock()
	rateHits = map[string][]time.Time{}
	domainRateHits = map[string][]time.Time{}
	rateMu.Unlock()

	e := echo.New()
	e.GET("/api.js", handleApiJs)

	ip := "203.0.113.22"
	for i := 0; i < domainRateLimit; i++ {
		req := httptest.NewRequest(http.MethodGet, "/api.js?d=domainkey", nil)
		req.RemoteAddr = ip + ":12345"
		rec := httptest.NewRecorder()
		e.ServeHTTP(rec, req)
		if rec.Code == http.StatusTooManyRequests {
			t.Fatalf("request %d: unexpected domain 429 before domain limit", i)
		}
	}
	req := httptest.NewRequest(http.MethodGet, "/api.js?d=domainkey", nil)
	req.RemoteAddr = ip + ":12345"
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusTooManyRequests {
		t.Fatalf("expected domain 429 after %d same-domain requests, got %d", domainRateLimit, rec.Code)
	}
}

func TestRateLimitMiddlewareBlocksBurst(t *testing.T) {
	rateMu.Lock()
	rateHits = map[string][]time.Time{}
	rateMu.Unlock()

	e := echo.New()
	e.GET("/api.js", func(c echo.Context) error {
		return c.String(http.StatusOK, "ok")
	}, rateLimitMiddleware)

	burst := rateLimit + 5
	for i := 0; i < burst; i++ {
		req := httptest.NewRequest(http.MethodGet, "/api.js", nil)
		req.RemoteAddr = "203.0.113.10:12345"
		rec := httptest.NewRecorder()
		e.ServeHTTP(rec, req)
		if i < rateLimit && rec.Code != http.StatusOK {
			t.Fatalf("request %d: expected 200, got %d", i, rec.Code)
		}
		if i >= rateLimit && rec.Code != http.StatusTooManyRequests {
			t.Fatalf("request %d: expected 429, got %d", i, rec.Code)
		}
	}
}
