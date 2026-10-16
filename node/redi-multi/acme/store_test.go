package acme

import "testing"

func TestResponseForPathRequiresHostAndToken(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "challenge-body",
	}})

	body, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/tok123")
	if !ok || body != "challenge-body" {
		t.Fatalf("expected challenge on matching host, got ok=%v body=%q", ok, body)
	}

	_, ok = ResponseForPath("evil.example", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("must not serve challenge for wrong host")
	}
}

func TestResponseForPathRejectsMalformedToken(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "ok-token",
		ResponseBody: "body",
	}})

	_, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/../ok-token")
	if ok {
		t.Fatal("path traversal token must not match")
	}
}
