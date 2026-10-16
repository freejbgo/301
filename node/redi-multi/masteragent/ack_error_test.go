package masteragent

import (
	"strings"
	"testing"
)

func TestSanitizeAckErrorStripsControlChars(t *testing.T) {
	got := sanitizeAckError("disk\r\nfull")
	if got != "diskfull" {
		t.Fatalf("got %q", got)
	}
}

func TestSanitizeAckErrorTruncates(t *testing.T) {
	got := sanitizeAckError(strings.Repeat("a", 2500))
	if len(got) != 2000 {
		t.Fatalf("len %d", len(got))
	}
}
