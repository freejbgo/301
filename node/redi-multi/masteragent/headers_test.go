package masteragent

import (
	"net/http"
	"testing"
)

func TestSetMasterAgentHeaders(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "https://master.example/api/nodes/revision", nil)
	if err != nil {
		t.Fatal(err)
	}
	setMasterAgentHeaders(req)
	if req.Header.Get("User-Agent") != "redi-multi/"+agentVersion {
		t.Fatalf("User-Agent: got %q", req.Header.Get("User-Agent"))
	}
}
