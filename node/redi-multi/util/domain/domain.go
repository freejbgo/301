package domain

import (
	"fmt"
	"strconv"
	"strings"

	"redi-multi/util/host"
)

type Decoded struct {
	Content string
}

func (d *Decoded) Encode() string {
	if host.ContainsControlChars(d.Content) {
		return ""
	}
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

const maxEncodedDomainLen = 4096
const maxEncodedParts = 256

func (d *Encoded) Decode() string {
	domainEncode := d.Content
	if host.ContainsControlChars(domainEncode) {
		return ""
	}
	if len(domainEncode) > maxEncodedDomainLen {
		return ""
	}
	var encodeSlice []rune
	encodeSliceStr := strings.Split(domainEncode, ",")
	if len(encodeSliceStr) > maxEncodedParts {
		return ""
	}
	for _, part := range encodeSliceStr {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		ab, err := strconv.Atoi(part)
		if err != nil || ab < 0 || ab > 0x10ffff {
			return ""
		}
		encodeSlice = append(encodeSlice, rune(ab))
	}
	domain := string(encodeSlice)
	return domain
}
