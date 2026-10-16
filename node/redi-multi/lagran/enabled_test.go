package lagran

import (
	"os"
	"testing"
)

func TestEnabledRespectsEnvDisable(t *testing.T) {
	t.Setenv("REDI_LAGRAN_DISABLED", "1")
	if Enabled() {
		t.Fatal("expected lagran disabled when REDI_LAGRAN_DISABLED=1")
	}
}

func TestEnabledWhenEnvUnset(t *testing.T) {
	os.Unsetenv("REDI_LAGRAN_DISABLED")
	if !Enabled() {
		t.Fatal("expected lagran enabled by default when env unset and config default true")
	}
}
