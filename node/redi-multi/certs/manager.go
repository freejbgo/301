package certs

import (
	"crypto/tls"
	"fmt"
	"strings"
	"sync"
)

type Entry struct {
	CertID      int
	Domain      string
	Certificate []byte
	PrivateKey  []byte
	ForceHTTPS  bool
}

var (
	mu      sync.RWMutex
	entries = map[string]Entry{}
	byID    = map[int]Entry{}
)

func ReplaceAll(list []Entry) {
	mu.Lock()
	entries = map[string]Entry{}
	byID = map[int]Entry{}
	for _, e := range list {
		d := strings.ToLower(strings.TrimSpace(e.Domain))
		if d == "" {
			continue
		}
		entries[d] = e
		byID[e.CertID] = e
	}
	mu.Unlock()
}

func ForceHTTPS(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	mu.RLock()
	e, ok := entries[host]
	mu.RUnlock()
	return ok && e.ForceHTTPS
}

func GetCertificate(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
	host := strings.ToLower(strings.TrimSpace(hello.ServerName))
	mu.RLock()
	e, ok := entries[host]
	mu.RUnlock()
	if !ok {
		return nil, fmt.Errorf("no certificate for %s", host)
	}
	cert, err := tls.X509KeyPair(e.Certificate, e.PrivateKey)
	if err != nil {
		return nil, err
	}
	return &cert, nil
}

func InstallEntry(e Entry) error {
	_, err := tls.X509KeyPair(e.Certificate, e.PrivateKey)
	if err != nil {
		return err
	}
	mu.Lock()
	d := strings.ToLower(strings.TrimSpace(e.Domain))
	entries[d] = e
	byID[e.CertID] = e
	mu.Unlock()
	return nil
}

func PendingAcks() []int {
	mu.RLock()
	defer mu.RUnlock()
	var ids []int
	for id := range byID {
		ids = append(ids, id)
	}
	return ids
}
