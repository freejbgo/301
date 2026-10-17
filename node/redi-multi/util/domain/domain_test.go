package domain

import "testing"

func TestEncodedDecodeRejectsControlChars(t *testing.T) {
	if (&Encoded{Content: "49,50\n"}).Decode() != "" {
		t.Fatal("expected empty for control chars in encoded domain")
	}
}

func TestEncodedDecodeRejectsGarbage(t *testing.T) {
	d := Encoded{Content: "foo,bar,999999999999"}
	if d.Decode() != "" {
		t.Fatal("expected empty decode for invalid codepoints")
	}
}

func TestEncodedDecodeRoundTrip(t *testing.T) {
	src := "example.com"
	enc := (&Decoded{Content: src}).Encode()
	got := (&Encoded{Content: enc}).Decode()
	if got != src {
		t.Fatalf("got %q want %q", got, src)
	}
}
