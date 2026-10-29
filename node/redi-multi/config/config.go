package config

import (
	"encoding/json"
	"io/ioutil"
	"log"
	"os"
	"strings"

	"redi-multi/util/redirecturl"
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
	configPath := strings.TrimSpace(os.Getenv("REDI_CONFIG"))
	if configPath == "" {
		configPath = "config.json"
	}
	config, err := ioutil.ReadFile(configPath)
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
	ServerIp = jsonStringOptional(payload, "server_ip", "")
	DefaultRedirectUrl = jsonStringRequired(payload, "default_redirect_url")
	if !redirecturl.IsAllowed(DefaultRedirectUrl) {
		log.Fatalf("config.json: default_redirect_url must be a valid http(s) URL with host")
	}
	Redirector = jsonStringMapOptional(payload, "redirector")
	if len(Redirector) > 0 {
		log.Printf("config: redirector JSON is ignored at runtime (legacy field)")
	}

	if raw, ok := payload["lagran"].(map[string]interface{}); ok && raw != nil {
		Lagran = raw
	} else {
		Lagran = DefaultLagran()
	}
	normalizeLagranMap()

	applyEnvOverrides()
	validateLoadedListenConfig()
	mergeLagranPortsFromListeners()
	logStartupSummary()
}
