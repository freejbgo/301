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

func TestReplaceAllSkipsMalformedDomain(t *testing.T) {
	ReplaceAll([]Entry{
		{CertID: 1, Domain: "good.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
		{CertID: 2, Domain: "bad\r\n.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
	})
	mu.RLock()
	if len(entries) != 1 {
		mu.RUnlock()
		t.Fatalf("expected 1 entry, got %d", len(entries))
	}
	mu.RUnlock()
}

func TestReplaceAllSkipsTrailingNewlineDomain(t *testing.T) {
	ReplaceAll([]Entry{
		{CertID: 1, Domain: "good.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
		{CertID: 2, Domain: "bad.test\n", Certificate: []byte("c"), PrivateKey: []byte("k")},
	})
	mu.RLock()
	if len(entries) != 1 {
		mu.RUnlock()
		t.Fatalf("expected 1 entry, got %d", len(entries))
	}
	mu.RUnlock()
}

func TestSyncFromMasterDropsRetiredIDs(t *testing.T) {
	ReplaceAll([]Entry{
		{CertID: 1, Domain: "a.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
		{CertID: 2, Domain: "b.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
	})
	SyncFromMaster([]Entry{
		{CertID: 3, Domain: "c.test", Certificate: []byte("c"), PrivateKey: []byte("k")},
	}, []int{2, 3})
	mu.RLock()
	if len(entries) != 2 {
		mu.RUnlock()
		t.Fatalf("expected 2 entries, got %d", len(entries))
	}
	if _, ok := byID[1]; ok {
		mu.RUnlock()
		t.Fatal("cert 1 should be removed (not in remote set)")
	}
	mu.RUnlock()
}
