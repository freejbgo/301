package masteragent

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"
)

func testCertKeyPEM(t *testing.T) (certPEM string, keyPEM string) {
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("rsa key: %v", err)
	}
	template := x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: "good.example"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(24 * time.Hour),
		DNSNames:     []string{"good.example"},
	}
	der, err := x509.CreateCertificate(rand.Reader, &template, &template, &key.PublicKey, key)
	if err != nil {
		t.Fatalf("create cert: %v", err)
	}
	certPEM = string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
	keyDER := x509.MarshalPKCS1PrivateKey(key)
	keyPEM = string(pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: keyDER}))
	return certPEM, keyPEM
}

func TestPullCertificatesPostsAckOnPemFailureAndSuccess(t *testing.T) {
	goodCert, goodKey := testCertKeyPEM(t)

	var mu sync.Mutex
	var ackBodies []map[string]interface{}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method + " " + r.URL.Path {
		case "GET /api/nodes/certificates":
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"certificates": []map[string]interface{}{
					{
						"cert_id":     1,
						"domain":      "bad.example",
						"certificate": "not-a-pem",
						"private_key": "not-a-key",
						"force_https": false,
					},
					{
						"cert_id":     2,
						"domain":      "good.example",
						"certificate": goodCert,
						"private_key": goodKey,
						"force_https": true,
					},
				},
			})
		case "POST /api/nodes/ack":
			var body map[string]interface{}
			_ = json.NewDecoder(r.Body).Decode(&body)
			mu.Lock()
			ackBodies = append(ackBodies, body)
			mu.Unlock()
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"ok":true}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	cfg := config{MasterURL: srv.URL, Token: "1.testtoken"}
	registered := true
	err := pullCertificates(srv.Client(), cfg, &registered)
	if err == nil {
		t.Fatal("expected error when one cert failed PEM validation")
	}

	mu.Lock()
	defer mu.Unlock()
	if len(ackBodies) < 2 {
		t.Fatalf("expected at least 2 ack posts, got %d", len(ackBodies))
	}
	var sawFail, sawOk bool
	for _, b := range ackBodies {
		id, _ := b["cert_id"].(float64)
		if int(id) == 1 {
			if b["ok"] != false {
				t.Fatalf("cert 1 ack expected ok=false, got %v", b["ok"])
			}
			sawFail = true
		}
		if int(id) == 2 {
			if b["ok"] != true {
				t.Fatalf("cert 2 ack expected ok=true, got %v", b["ok"])
			}
			sawOk = true
		}
	}
	if !sawFail || !sawOk {
		t.Fatalf("missing expected acks: fail=%v ok=%v bodies=%v", sawFail, sawOk, ackBodies)
	}
}
