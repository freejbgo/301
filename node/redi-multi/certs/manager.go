package certs

import (
	"crypto/tls"
	"fmt"
	"sync"

	hostutil "redi-multi/util/host"
)

type Entry struct {
	CertID      int
	Domain      string
	Certificate []byte
	PrivateKey  []byte
	ForceHTTPS  bool
}

var (
	mu           sync.RWMutex
	entries      = map[string]Entry{}
	byID         = map[int]Entry{}
	parsedByCert = map[int]*tls.Certificate{}
)

func clearParsedCacheLocked() {
	parsedByCert = map[int]*tls.Certificate{}
}

func ReplaceAll(list []Entry) {
	mu.Lock()
	entries = map[string]Entry{}
	byID = map[int]Entry{}
	clearParsedCacheLocked()
	upsertIntoMaps(entries, byID, list)
	mu.Unlock()
}

// UpsertMany 合并成功安装的证书，不移除未出现在 list 中的既有条目（用于部分 PEM 校验失败时）。
func UpsertMany(list []Entry) {
	mu.Lock()
	upsertIntoMaps(entries, byID, list)
	clearParsedCacheLocked()
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
	clearParsedCacheLocked()
	mu.Unlock()
}

func upsertIntoMaps(hostMap map[string]Entry, idMap map[int]Entry, list []Entry) {
	for _, e := range list {
		if hostutil.ContainsControlChars(e.Domain) {
			continue
		}
		d := hostutil.AcmeHostKey(e.Domain)
		if d == "" {
			continue
		}
		hostMap[d] = e
		idMap[e.CertID] = e
	}
}

func HasTLSMaterial(host string) bool {
	if hostutil.ContainsControlChars(host) {
		return false
	}
	host = hostutil.AcmeHostKey(host)
	if host == "" {
		return false
	}
	mu.RLock()
	_, ok := entries[host]
	mu.RUnlock()
	return ok
}

func ForceHTTPS(host string) bool {
	if hostutil.ContainsControlChars(host) {
		return false
	}
	host = hostutil.AcmeHostKey(host)
	if host == "" {
		return false
	}
	mu.RLock()
	e, ok := entries[host]
	mu.RUnlock()
	return ok && e.ForceHTTPS
}

func GetCertificate(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
	if hello == nil || hostutil.ContainsControlChars(hello.ServerName) {
		return nil, fmt.Errorf("no certificate for invalid host")
	}
	host := hostutil.AcmeHostKey(hello.ServerName)
	if host == "" {
		return nil, fmt.Errorf("no certificate for invalid host")
	}
	mu.RLock()
	e, ok := entries[host]
	if !ok {
		mu.RUnlock()
		return nil, fmt.Errorf("no certificate for %s", host)
	}
	if cached, hit := parsedByCert[e.CertID]; hit {
		mu.RUnlock()
		return cached, nil
	}
	certPEM := append([]byte(nil), e.Certificate...)
	keyPEM := append([]byte(nil), e.PrivateKey...)
	certID := e.CertID
	mu.RUnlock()

	cert, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return nil, err
	}

	mu.Lock()
	if cached, hit := parsedByCert[certID]; hit {
		mu.Unlock()
		return cached, nil
	}
	parsed := cert
	parsedByCert[certID] = &parsed
	mu.Unlock()
	return &parsed, nil
}

func InstallEntry(e Entry) error {
	_, err := tls.X509KeyPair(e.Certificate, e.PrivateKey)
	if err != nil {
		return err
	}
	mu.Lock()
	if hostutil.ContainsControlChars(e.Domain) {
		mu.Unlock()
		return fmt.Errorf("invalid domain")
	}
	d := hostutil.AcmeHostKey(e.Domain)
	if d == "" {
		mu.Unlock()
		return fmt.Errorf("invalid domain")
	}
	entries[d] = e
	byID[e.CertID] = e
	delete(parsedByCert, e.CertID)
	mu.Unlock()
	return nil
}
