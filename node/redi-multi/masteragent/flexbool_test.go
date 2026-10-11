package masteragent

import (
	"encoding/json"
	"testing"
)

func TestFlexBool(t *testing.T) {
	cases := []struct {
		raw  string
		want bool
	}{
		{`true`, true},
		{`false`, false},
		{`1`, true},
		{`0`, false},
		{`"1"`, true},
		{`"0"`, false},
	}
	for _, c := range cases {
		var got flexBool
		if err := json.Unmarshal([]byte(c.raw), &got); err != nil {
			t.Fatalf("%s: %v", c.raw, err)
		}
		if bool(got) != c.want {
			t.Fatalf("%s => %v, want %v", c.raw, got, c.want)
		}
	}
}
