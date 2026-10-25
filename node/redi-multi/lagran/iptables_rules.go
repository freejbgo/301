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

var lagranNFQueueRules = []lagranNFQueueRule{
	{boolKey: "syn_ack", tcpMatch: "SYN,ACK", queue: "1000:1127"},
	{boolKey: "ack", tcpMatch: "ACK", queue: "2000:2127"},
	{boolKey: "psh_ack", tcpMatch: "PSH,ACK", queue: "3000:3127"},
	{boolKey: "fin_ack", tcpMatch: "FIN,ACK", queue: "4000:4127"},
}

func appendLagranRules(ipt *iptables.IPTables, sport string, familyLabel string) bool {
	ok := true
	for _, rule := range lagranNFQueueRules {
		if !config.LagranBool(rule.boolKey, true) {
			continue
		}
		err := ipt.AppendUnique("filter", "OUTPUT",
			"-p", "tcp", "-m", "multiport", "--sport", sport,
			"--tcp-flags", "SYN,RST,ACK,FIN,PSH", rule.tcpMatch,
			"-j", "NFQUEUE", "--queue-balance", rule.queue, "--queue-bypass")
		if err != nil {
			ok = false
			logrus.Errorf("[lagran] failed to set %s OUTPUT chain (%s): %v", familyLabel, rule.tcpMatch, err)
		}
	}
	return ok
}

func deleteLagranRules(ipt *iptables.IPTables, sport string, familyLabel string) {
	for _, rule := range lagranNFQueueRules {
		if !config.LagranBool(rule.boolKey, true) {
			continue
		}
		err := ipt.Delete("filter", "OUTPUT",
			"-p", "tcp", "-m", "multiport", "--sport", sport,
			"--tcp-flags", "SYN,RST,ACK,FIN,PSH", rule.tcpMatch,
			"-j", "NFQUEUE", "--queue-balance", rule.queue, "--queue-bypass")
		if err != nil {
			logrus.Errorf("[lagran] failed to delete %s OUTPUT chain (%s): %v", familyLabel, rule.tcpMatch, err)
		}
	}
}
