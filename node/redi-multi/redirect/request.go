package redirect

import (
	"bufio"
	"io"
	"net"
	"strings"
)

type httpRequest struct {
	Method string
	Path   string
	Host   string
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
		if strings.HasPrefix(strings.ToLower(h), "host:") {
			req.Host = strings.TrimSpace(h[5:])
			if idx := strings.Index(req.Host, ":"); idx > 0 {
				req.Host = req.Host[:idx]
			}
		}
	}
	return req, nil
}
