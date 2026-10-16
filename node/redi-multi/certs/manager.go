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
	upsertIntoMaps(entries, byID, list)
	mu.Unlock()
}

// UpsertMany 合并成功安装的证书，不移除未出现在 list 中的既有条目（用于部分 PEM 校验失败时）。
func UpsertMany(list []Entry) {
	mu.Lock()
	upsertIntoMaps(entries, byID, list)
	mu.Unlock()
}

// SyncFromMaster 在部分 PEM 失败时：更新校验通过的条目，摘掉主控已不再下发的 cert_id，失败行保留本地旧证书。
func SyncFromMaster(valid []Entry, remoteIDs []int) {
	mu.Lock()
	allowed := make(map[int]bool, len(remoteIDs))
	for _, id := range remoteIDs {
		if id > 0 {
			allowed[id] = true
		}
	}
	for host, e := range entries {
		if !allowed[e.CertID] {
			delete(entries, host)
		}
	}
	for id := range byID {
		if !allowed[id] {
			delete(byID, id)
		}
	}
	upsertIntoMaps(entries, byID, valid)
	mu.Unlock()
}

func upsertIntoMaps(hostMap map[string]Entry, idMap map[int]Entry, list []Entry) {
	for _, e := range list {
		d := strings.ToLower(strings.TrimSpace(e.Domain))
		if d == "" || strings.ContainsAny(d, "\r\n\x00") {
			continue
		}
		hostMap[d] = e
		idMap[e.CertID] = e
	}
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

