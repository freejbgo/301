package masteragent

import (
	"net/http"
	"testing"
)

func TestShouldClearRegistration(t *testing.T) {
	if !shouldClearRegistration("/api/nodes/certificates", http.StatusUnauthorized) {
		t.Fatal("expected 401 to clear registration")
	}
	if shouldClearRegistration("/api/nodes/ack", http.StatusForbidden) {
		t.Fatal("ack 403 must not clear registration")
	}
	if !shouldClearRegistration("/api/nodes/certificates", http.StatusForbidden) {
		t.Fatal("expected non-ack 403 to clear registration")
	}
}
