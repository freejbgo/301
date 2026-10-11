package masteragent

import (
	"bytes"
	"fmt"
)

// flexBool 接受 JSON 布尔、0/1 和 "0"/"1"。主控规则字段可能按数据库原值输出。
type flexBool bool

func (b *flexBool) UnmarshalJSON(data []byte) error {
	data = bytes.TrimSpace(data)
	switch string(data) {
	case "true", "1", `"1"`, `"true"`:
		*b = true
		return nil
	case "false", "0", `"0"`, `"false"`, "null":
		*b = false
		return nil
	default:
		return fmt.Errorf("invalid bool %s", data)
	}
}
