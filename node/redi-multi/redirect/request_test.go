package redirect

import "testing"

func TestHostWithoutPort(t *testing.T) {
	cases := map[string]string{
		"foo.com":           "foo.com",
		"foo.com:80":        "foo.com",
		"[2001:db8::1]":     "[2001:db8::1]",
		"[2001:db8::1]:443": "2001:db8::1",
	}
	for in, want := range cases {
		if got := hostWithoutPort(in); got != want {
			t.Fatalf("%q => %q, want %q", in, got, want)
		}
	}
}
