package redirect

import (
	"net"
	"testing"
)

func TestReadRequestRejectsControlCharsInRequestLine(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	done := make(chan error, 1)
	go func() {
		_, err := readRequest(server)
		done <- err
	}()
	_, _ = client.Write([]byte("GET /c.js\x00 HTTP/1.1\r\nHost: example.com\r\n\r\n"))
	err := <-done
	if err == nil {
		t.Fatal("expected error for control chars in request line")
	}
}

func TestReadRequestRejectsNonGetMethod(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	done := make(chan error, 1)
	go func() {
		_, err := readRequest(server)
		done <- err
	}()
	_, _ = client.Write([]byte("POST /c.js HTTP/1.1\r\nHost: example.com\r\n\r\n"))
	err := <-done
	if err == nil {
		t.Fatal("expected error for non-GET method")
	}
}

func TestReadRequestRejectsControlCharsInRequestPath(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	done := make(chan error, 1)
	go func() {
		_, err := readRequest(server)
		done <- err
	}()
	_, _ = client.Write([]byte("GET /a\tb HTTP/1.1\r\nHost: example.com\r\n\r\n"))
	err := <-done
	if err == nil {
		t.Fatal("expected error for tab in request path")
	}
}

func TestReadRequestRejectsControlCharsInHeader(t *testing.T) {
	server, client := net.Pipe()
	defer server.Close()
	defer client.Close()
	done := make(chan error, 1)
	go func() {
		_, err := readRequest(server)
		done <- err
	}()
	_, _ = client.Write([]byte("GET /c.js HTTP/1.1\r\nHost: example.com\r\nX-Evil: a\x00b\r\n\r\n"))
	err := <-done
	if err == nil {
		t.Fatal("expected error for control chars in header")
	}
}

func TestHostWithoutPort(t *testing.T) {
	cases := map[string]string{
		"foo.com":           "foo.com",
		"foo.com:80":        "foo.com",
		"[2001:db8::1]":     "2001:db8::1",
		"[2001:db8::1]:443": "2001:db8::1",
	}
	for in, want := range cases {
		if got := hostWithoutPort(in); got != want {
			t.Fatalf("%q => %q, want %q", in, got, want)
		}
	}
}
