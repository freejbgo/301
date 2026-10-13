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

	Debug = jsonBool(payload, "debug", false)
	Addr = jsonStringRequired(payload, "addr")
	TlsAddr = resolveTlsAddrFromJSON(payload)
	ApiListen = jsonStringOptional(payload, "api_listen", "127.0.0.1:8080")
	ServerIp = jsonStringRequired(payload, "server_ip")
	DefaultRedirectUrl = jsonStringRequired(payload, "default_redirect_url")
	Redirector = jsonStringMapOptional(payload, "redirector")

	if raw, ok := payload["lagran"].(map[string]interface{}); ok && raw != nil {
		Lagran = raw
	} else {
		Lagran = DefaultLagran()
	}
	normalizeLagranMap()

	applyEnvOverrides()
	mergeLagranPortsFromListeners()
	logStartupSummary()
}
