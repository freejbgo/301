package lagran

import (
	"context"
	"redi-multi/config"
	"regexp"
	"time"

	"github.com/coreos/go-iptables/iptables"
	"github.com/florianl/go-nfqueue"
	"github.com/sirupsen/logrus"
)

var portDigitRe = regexp.MustCompile(`\d+`)

type queuePoolSpec struct {
	label   string
	from    int
	to      int
	boolKey string
}

func Run() {
	if err := reloadEntry(); err != nil {
		logrus.Errorf("[lagran] %v (check cap_net_admin / root)", err)
	}
}

func setIptable(sport string) bool {
	logrus.Debugf("[lagran] sport:%s", sport)
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] iptables new error:%v\n", err)
		return false
	}
	v4ok := appendLagranRules(ipt, sport, "iptables")

	ip6, err := iptables.New(iptables.IPFamily(iptables.ProtocolIPv6))
	if err != nil {
		logrus.Warnf("[lagran] ip6tables new error (IPv4 rules still apply): %v", err)
	} else if !appendLagranRules(ip6, sport, "ip6tables") {
		logrus.Warnf("[lagran] ip6tables OUTPUT rules were not fully installed (IPv4 rules still apply)")
	}
	return v4ok
}

func UnsetIptable(sport string) {
	removeFamily(sport, config.LagranBool("syn_ack", true), config.LagranBool("ack", true))
}

func packetHandle(queueNum int) {
	nfqconfig := nfqueue.Config{
		NfQueue:      uint16(queueNum),
		MaxPacketLen: 0xFFFF,
		MaxQueueLen:  0xFF,
		Copymode:     nfqueue.NfQnlCopyPacket,
		WriteTimeout: 15 * time.Millisecond,
	}

	nf, err := nfqueue.Open(&nfqconfig)
	if err != nil {
		logrus.Errorf("[lagran] failed to open nfqueue socket queue=%d: %v", queueNum, err)
		return
	}

	defer nf.Close()

	ctx, cancelFunc := context.WithCancel(context.Background())
	defer cancelFunc()

	fn := func(a nfqueue.Attribute) int {
		if a.PacketID == nil || a.Payload == nil || len(*a.Payload) == 0 {
			if a.PacketID != nil {
				_ = nf.SetVerdict(*a.PacketID, nfqueue.NfAccept)
			}
			return 0
		}
		id := *a.PacketID
		raw := *a.Payload
		logrus.Debugf("[lagran] PKT[%03d]\t\n", id)

		if out, ok := tryRewriteOutboundTCP(raw); ok {
			logrus.Debugf("[lagran] Set TCP window on outbound packet len=%d\n", len(out))
			if err := nf.SetVerdictModPacket(id, nfqueue.NfAccept, out); err != nil {
				logrus.Errorf("[lagran] failed to SetVerdictModified error: %v\n", err)
			}
			return 0
		}
		if err := nf.SetVerdict(id, nfqueue.NfAccept); err != nil {
			logrus.Errorf("[lagran] failed to SetVerdict, err: %v\n", err)
		}
		return 0
	}

	err = nf.RegisterWithErrorFunc(ctx, fn, func(e error) int {
		if e != nil {
			logrus.Errorf("[lagran] failed to RegisterWithErrorFunc, err: %v\n", e)
		}
		return 0
	})
	if err != nil {
		logrus.Errorf("[lagran] error: %v\n", err)
		return
	}
	<-ctx.Done()
}
