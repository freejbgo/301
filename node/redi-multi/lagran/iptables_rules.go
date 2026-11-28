package lagran

import (
	"redi-multi/config"

	"github.com/coreos/go-iptables/iptables"
	"github.com/sirupsen/logrus"
)

type lagranNFQueueRule struct {
	boolKey  string
	tcpMatch string
	queue    string
}

// 只把 SYN+ACK 和纯 ACK 送进队列。PSH+ACK / FIN+ACK 不再改写，避免把响应数据拉进用户态。
var lagranNFQueueRules = []lagranNFQueueRule{
	{boolKey: "syn_ack", tcpMatch: "SYN,ACK", queue: "1000:1127"},
	{boolKey: "ack", tcpMatch: "ACK", queue: "2000:2127"},
}

// 旧版会安装这两条。退出时仍尝试删除，避免升级后规则残留。
var lagranLegacyNFQueueRules = []lagranNFQueueRule{
	{boolKey: "psh_ack", tcpMatch: "PSH,ACK", queue: "3000:3127"},
	{boolKey: "fin_ack", tcpMatch: "FIN,ACK", queue: "4000:4127"},
}

func ruleSpec(sport, tcpMatch, queue string) []string {
	return []string{
		"-p", "tcp", "-m", "multiport", "--sport", sport,
		"--tcp-flags", "SYN,RST,ACK,FIN,PSH", tcpMatch,
		"-j", "NFQUEUE", "--queue-balance", queue, "--queue-bypass",
	}
}

func appendLagranRules(ipt *iptables.IPTables, sport string, familyLabel string) bool {
	ok := true
	for _, rule := range lagranNFQueueRules {
		if !config.LagranBool(rule.boolKey, true) {
			continue
		}
		err := ipt.AppendUnique("filter", "OUTPUT", ruleSpec(sport, rule.tcpMatch, rule.queue)...)
		if err != nil {
			ok = false
			logrus.Errorf("[lagran] failed to set %s OUTPUT chain (%s): %v", familyLabel, rule.tcpMatch, err)
		}
	}
	return ok
}

func deleteLagranRules(ipt *iptables.IPTables, sport string, familyLabel string, syn, ack bool) {
	flags := map[string]bool{"syn_ack": syn, "ack": ack}
	for _, rule := range lagranNFQueueRules {
		if !flags[rule.boolKey] {
			continue
		}
		err := ipt.Delete("filter", "OUTPUT", ruleSpec(sport, rule.tcpMatch, rule.queue)...)
		if err != nil {
			logrus.Errorf("[lagran] failed to delete %s OUTPUT chain (%s): %v", familyLabel, rule.tcpMatch, err)
		}
	}
	for _, rule := range lagranLegacyNFQueueRules {
		err := ipt.Delete("filter", "OUTPUT", ruleSpec(sport, rule.tcpMatch, rule.queue)...)
		if err != nil {
			logrus.Debugf("[lagran] legacy %s OUTPUT rule %s not removed: %v", familyLabel, rule.tcpMatch, err)
		}
	}
}
