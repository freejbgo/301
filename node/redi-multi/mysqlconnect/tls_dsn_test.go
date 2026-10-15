package mysqlconnect

import "testing"

func TestAppendTlsToDSN(t *testing.T) {
	base := "user:pass@tcp(127.0.0.1:3306)/db?charset=utf8mb4&parseTime=true"
	cases := []struct {
		mode string
		want string
		err  bool
	}{
		{"", base, false},
		{"1", base + "&tls=true", false},
		{"true", base + "&tls=true", false},
		{"skip-verify", base + "&tls=redi-skip-verify", false},
		{"bogus", "", true},
	}
	for _, tc := range cases {
		got, err := appendTlsToDSN(base, tc.mode)
		if tc.err {
			if err == nil {
				t.Fatalf("mode %q: expected error", tc.mode)
			}
			continue
		}
		if err != nil {
			t.Fatalf("mode %q: %v", tc.mode, err)
		}
		if got != tc.want {
			t.Fatalf("mode %q: got %q want %q", tc.mode, got, tc.want)
		}
	}
}
