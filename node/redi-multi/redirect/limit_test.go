package redirect

import "testing"

func TestTryAcquireConnectionPairsWithRelease(t *testing.T) {
	if !TryAcquireConnection() {
		t.Fatal("expected acquire")
	}
	releaseConnection()
}
