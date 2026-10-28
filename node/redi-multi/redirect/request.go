package redirect

import (
	"bufio"
	"io"
	"net"
	"strings"

	hostutil "redi-multi/util/host"
)

const maxRequestLineLen = 8192
const maxHeaderLines = 128

type httpRequest struct {
	Method    string
	Path      string
	Host      string
	UserAgent string
}

func readRequest(conn net.Conn) (*httpRequest, error) {
	reader := bufio.NewReader(conn)
	line, err := readLimitedLine(reader, maxRequestLineLen)
	if err != nil {
		return nil, err
	}
	trimmed := strings.TrimSpace(line)
	if hostutil.ContainsControlChars(trimmed) {
		return nil, io.ErrUnexpectedEOF
	}
	parts := strings.Split(trimmed, " ")
	if len(parts) < 2 {
		return nil, io.ErrUnexpectedEOF
	}
	if strings.ToUpper(parts[0]) != "GET" {
		return nil, io.ErrUnexpectedEOF
	}
	req := &httpRequest{Method: parts[0], Path: parts[1]}
	for n := 0; n < maxHeaderLines; n++ {
		h, err := readLimitedLine(reader, maxRequestLineLen)
		if err != nil {
			return nil, err
		}
		h = strings.TrimRight(h, "\r\n")
		if h == "" {
			break
		}
		if hostutil.ContainsControlChars(h) {
			return nil, io.ErrUnexpectedEOF
		}
		lower := strings.ToLower(h)
		if strings.HasPrefix(lower, "host:") {
			req.Host = sanitizeHost(hostWithoutPort(strings.TrimSpace(h[5:])))
		}
		if strings.HasPrefix(lower, "user-agent:") {
			req.UserAgent = sanitizeUserAgent(h[len("user-agent:"):])
		}
	}
	return req, nil
}

func hostWithoutPort(host string) string {
	host = strings.TrimSpace(host)
	if strings.HasPrefix(host, "[") {
		if h, _, err := net.SplitHostPort(host); err == nil {
			return strings.TrimSpace(h)
		}
		if idx := strings.LastIndex(host, "]"); idx > 0 {
			return strings.TrimSpace(host[1:idx])
		}
	}
	if h, _, err := net.SplitHostPort(host); err == nil {
		return h
	}
	return host
}

func readLimitedLine(reader *bufio.Reader, max int) (string, error) {
	line, err := reader.ReadString('\n')
	if err != nil {
		return line, err
	}
	if len(line) > max {
		return "", io.ErrUnexpectedEOF
	}
	return line, nil
}
