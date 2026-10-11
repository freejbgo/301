package config

import (
	"encoding/json"
	"io/ioutil"
	"log"
)

var (
	Debug              bool
	Addr               string
	TlsAddr            string
	ApiListen          string
	ServerIp           string
	DefaultRedirectUrl string
	Redirector         map[string]interface{}
	Lagran             map[string]interface{}
)

func Init() {
	config, err := ioutil.ReadFile("config.json")
	if err != nil {
		log.Fatal("Error when opening file: ", err)
	}
	var payload map[string]interface{}
	err = json.Unmarshal(config, &payload)
	if err != nil {
		log.Fatal("Error during Unmarshal(): ", err)
	}
	Debug = payload["debug"].(bool)
	Addr = payload["addr"].(string)
	if v, ok := payload["tls_addr"].(string); ok {
		TlsAddr = v
	}
	if TlsAddr == "" {
		TlsAddr = "0.0.0.0:443"
	}
	if v, ok := payload["api_listen"].(string); ok {
		ApiListen = v
	}
	ServerIp = payload["server_ip"].(string)
	DefaultRedirectUrl = payload["default_redirect_url"].(string)
	Redirector = payload["redirector"].(map[string]interface{})
	Lagran = payload["lagran"].(map[string]interface{})

	// lagran.port 与 addr / tls_addr 对齐：iptables multiport 格式为 "80,443"（逗号、无空格）
	if Lagran != nil {
		existing := ""
		if v, ok := Lagran["port"].(string); ok {
			existing = v
		}
		Lagran["port"] = MergeListenerPorts(existing, Addr, TlsAddr)
	}
}
