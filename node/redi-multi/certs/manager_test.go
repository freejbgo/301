package certs

import "testing"

func TestUpsertManyPreservesOtherCerts(t *testing.T) {
	ReplaceAll([]Entry{
		{CertID: 1, Domain: "a.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
		{CertID: 2, Domain: "b.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
	})
	UpsertMany([]Entry{
		{CertID: 3, Domain: "c.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
	})
	mu.RLock()
	if len(entries) != 3 {
		mu.RUnlock()
		t.Fatalf("expected 3 entries, got %d", len(entries))
	}
	mu.RUnlock()
}
