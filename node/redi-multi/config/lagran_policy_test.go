package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestApplyLagranPolicyRejectsWithoutMutating(t *testing.T) {
	prev := Lagran
	t.Cleanup(func() { Lagran = prev })
	Lagran = DefaultLagran()
	Lagran["window_scale"] = 7

	err := ApplyLagranPolicy(LagranPolicy{WindowScale: 15})
	if err == nil {
		t.Fatal("expected validation error")
	}
	if got := LagranInt("window_scale", 0); got != 7 {
		t.Fatalf("window_scale changed to %d", got)
	}
}

func TestApplyLagranPolicyKeepsExtraPorts(t *testing.T) {
	prev := Lagran
	t.Cleanup(func() { Lagran = prev })
	Lagran = DefaultLagran()
	Lagran["window"] = map[string]uint16{"80": 1, "443": 1, "8080": 9}

	err := ApplyLagranPolicy(LagranPolicy{
		Enabled:             false,
		SynAck:              true,
		Ack:                 false,
		WindowScale:         6,
		ConfusionTimes:      0,
		SplitNumber:         3,
		WindowAfterSplit:    1000,
		WindowConfusionLast: 2000,
		Window:              map[string]int{"80": 4, "443": 5},
	})
	if err != nil {
		t.Fatal(err)
	}
	if LagranBool("enabled", true) {
		t.Fatal("expected enabled false")
	}
	if LagranBool("ack", true) {
		t.Fatal("expected ack false")
	}
	if LagranWindow("80") != 4 || LagranWindow("443") != 5 || LagranWindow("8080") != 9 {
		t.Fatalf("windows 80=%d 443=%d 8080=%d", LagranWindow("80"), LagranWindow("443"), LagranWindow("8080"))
	}
	if LagranInt("window_after_split", 0) != 1000 || LagranInt("window_confusion_last", 0) != 2000 {
		t.Fatalf("after=%d last=%d", LagranInt("window_after_split", 0), LagranInt("window_confusion_last", 0))
	}
}

func TestPersistLagranSectionKeepsPortAndOtherKeys(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.json")
	original := []byte("{\n  \"addr\": \"0.0.0.0:80\",\n  \"lagran\": {\n    \"enabled\": true,\n    \"port\": \"80,443\",\n    \"note\": \"keep\",\n    \"window\": {\"80\": 1, \"443\": 1, \"8080\": 9}\n  }\n}\n")
	if err := os.WriteFile(path, original, 0644); err != nil {
		t.Fatal(err)
	}
	prevPath := loadedConfigPath
	prev := Lagran
	t.Cleanup(func() {
		loadedConfigPath = prevPath
		Lagran = prev
	})
	loadedConfigPath = path
	Lagran = DefaultLagran()
	Lagran["port"] = "80,443"
	Lagran["window"] = map[string]uint16{"80": 1, "443": 1, "8080": 9}
	if err := ApplyLagranPolicy(LagranPolicy{
		Enabled:             true,
		SynAck:              true,
		Ack:                 true,
		WindowScale:         7,
		ConfusionTimes:      0,
		SplitNumber:         7,
		WindowAfterSplit:    28960,
		WindowConfusionLast: 65535,
		Window:              map[string]int{"80": 2, "443": 3},
	}); err != nil {
		t.Fatal(err)
	}
	if err := PersistLagranSection(); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	var payload map[string]interface{}
	if err := json.Unmarshal(raw, &payload); err != nil {
		t.Fatal(err)
	}
	if payload["addr"] != "0.0.0.0:80" {
		t.Fatalf("addr %v", payload["addr"])
	}
	section, _ := payload["lagran"].(map[string]interface{})
	if section["port"] != "80,443" || section["note"] != "keep" {
		t.Fatalf("section %#v", section)
	}
	if section["window_after_split"] != float64(28960) {
		t.Fatalf("after %v", section["window_after_split"])
	}
	windows, _ := section["window"].(map[string]interface{})
	if windows["80"] != float64(2) || windows["443"] != float64(3) || windows["8080"] != float64(9) {
		t.Fatalf("windows %#v", windows)
	}
}
