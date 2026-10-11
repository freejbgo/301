package redirect

import (
	"bufio"
	"io"
	"net"
	"strings"
)

type httpRequest struct {
	Method    string
	Path      string
	Host      string
	UserAgent string
}

func readRequest(conn net.Conn) (*httpRequest, error) {
	reader := bufio.NewReader(conn)
	line, err := reader.ReadString('\n')
	if err != nil {
		return nil, err
	}
	parts := strings.Split(strings.TrimSpace(line), " ")
	if len(parts) < 2 {
		return nil, io.ErrUnexpectedEOF
	}
	req := &httpRequest{Method: parts[0], Path: parts[1]}
	for {
		h, err := reader.ReadString('\n')
		if err != nil {
			return req, nil
		}
		h = strings.TrimRight(h, "\r\n")
		if h == "" {
			break
		}
		lower := strings.ToLower(h)
		if strings.HasPrefix(lower, "host:") {
			req.Host = hostWithoutPort(strings.TrimSpace(h[5:]))
		}
		if strings.HasPrefix(lower, "user-agent:") {
			req.UserAgent = strings.TrimSpace(h[len("user-agent:"):])
		}
	}
	return req, nil
}

func hostWithoutPort(host string) string {
	host = strings.TrimSpace(host)
	if h, _, err := net.SplitHostPort(host); err == nil {
		return h
	}
	return host
}
