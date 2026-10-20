package acme

import "testing"

func TestResponseForPathNormalizesRequestHost(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "challenge-body",
	}})

	body, ok := ResponseForPath("https://Example.COM/", "/.well-known/acme-challenge/tok123")
	if !ok || body != "challenge-body" {
		t.Fatalf("expected challenge when request Host uses scheme/case, got ok=%v body=%q", ok, body)
	}
}

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

func TestReplaceAllSkipsResponseBodyWithControlChars(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "bo\r\ndy",
	}})

	_, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("control chars in response body must not be stored")
	}
}

func TestReplaceAllSkipsEmptyResponseBody(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "   ",
	}})

	_, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("empty response body must not be stored")
	}
}

func TestReplaceAllSkipsIPv4LiteralDomain(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "1.2.3.4",
		Token:        "tok123",
		ResponseBody: "body",
	}})

	_, ok := ResponseForPath("1.2.3.4", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("IPv4 literal must not be stored as ACME challenge host")
	}
}

func TestReplaceAllSkipsInvalidDomain(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "evil\t.com",
		Token:        "tok123",
		ResponseBody: "body",
	}})

	_, ok := ResponseForPath("evil.com", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("tab domain must not be stored")
	}
}

func TestHttp01TokenValidRejectsTrailingNewline(t *testing.T) {
	if Http01TokenValid("ok-token\n") {
		t.Fatal("trailing newline token must be invalid")
	}
}

func TestReplaceAllSkipsTrailingNewlineInResponseBody(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "body\n",
	}})

	_, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/tok123")
	if ok {
		t.Fatal("trailing newline in response body must not be stored")
	}
}

func TestResponseForPathRejectsPathWithControlChars(t *testing.T) {
	ReplaceAll([]Item{{
		DomainName:   "example.com",
		Token:        "tok123",
		ResponseBody: "body",
	}})

	_, ok := ResponseForPath("example.com", "/.well-known/acme-challenge/tok123\n")
	if ok {
		t.Fatal("path with trailing newline must not match")
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
