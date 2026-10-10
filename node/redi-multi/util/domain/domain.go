package domain

import (
	"fmt"
	"github.com/unknwon/com"
	"strings"
)

type Decoded struct {
	Content string
}

func (d *Decoded) Encode() string {
	aa := []rune(d.Content)
	res := ""
	for _, r := range aa {
		res += fmt.Sprintf("%v,", r)
	}
	return strings.TrimRight(res, ",")
}

type Encoded struct {
	Content string
}

func (d *Encoded) Decode() string {
	domainEncode := d.Content
	var encodeSlice []rune
	encodeSliceStr := strings.Split(domainEncode, ",")
	for _, r := range encodeSliceStr {
		ab := com.StrTo(r).MustInt()
		encodeSlice = append(encodeSlice, rune(ab))
	}
	domain := string(encodeSlice)
	return domain
}
