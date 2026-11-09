package https

import (
	"context"
	"crypto/tls"
	"net"
	"redi-multi/certs"
	"redi-multi/redirect"
	"syscall"

	"github.com/sirupsen/logrus"
	"golang.org/x/sys/unix"
)

func Start(listen string) {
	if listen == "" {
		return
	}
	cfg := net.ListenConfig{
		Control: func(network, address string, c syscall.RawConn) error {
			return c.Control(func(fd uintptr) {
				// 与 http 监听器一致：极小 SO_RCVBUF 配合 lagran；见 node/README.md
				syscall.SetsockoptInt(int(fd), syscall.SOL_SOCKET, unix.SO_RCVBUF, 1)
				linger := syscall.Linger{Onoff: 1, Linger: 0}
				syscall.SetsockoptLinger(int(fd), syscall.SOL_SOCKET, unix.SO_LINGER, &linger)
				syscall.SetsockoptInt(int(fd), syscall.IPPROTO_TCP, unix.TCP_NODELAY, 1)
			})
		},
	}
	ln, err := cfg.Listen(context.Background(), "tcp", listen)
	if err != nil {
		logrus.Fatalf("[https] listen fail: %v", err)
	}
	tlsCfg := &tls.Config{
		MinVersion: tls.VersionTLS12,
		GetCertificate: certs.GetCertificate,
	}
	tlsLn := tls.NewListener(ln, tlsCfg)
	logrus.Infof("[https] listening on %s", listen)
	for {
		conn, err := tlsLn.Accept()
		if err != nil {
			logrus.Debugf("[https] accept: %v", err)
			continue
		}
		if !redirect.TryAcquireConnection() {
			conn.Close()
			continue
		}
		go redirect.ProcessWithAcquiredSlot(conn, true)
	}
}
