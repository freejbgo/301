package config

import (
	"encoding/json"
	"io/ioutil"
	"log"
	"strings"
)

var (
	Debug              bool
	Addr               string
	TlsAddr            string
	TlsConfigured      bool
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
	TlsConfigured = false
	if v, ok := payload["tls_addr"]; ok {
		TlsConfigured = true
		if s, ok := v.(string); ok {
			TlsAddr = strings.TrimSpace(s)
		}
	} else {
		TlsAddr = "0.0.0.0:443"
	}
	if v, ok := payload["api_listen"].(string); ok {
		ApiListen = v
	}
	ServerIp = payload["server_ip"].(string)
	DefaultRedirectUrl = payload["default_redirect_url"].(string)
	Redirector = payload["redirector"].(map[string]interface{})

	if raw, ok := payload["lagran"].(map[string]interface{}); ok && raw != nil {
		Lagran = raw
	} else {
		Lagran = DefaultLagran()
	}

	existing := ""
	if v, ok := Lagran["port"].(string); ok {
		existing = v
	}
	Lagran["port"] = MergeListenerPorts(existing, Addr, TlsAddr)
}
