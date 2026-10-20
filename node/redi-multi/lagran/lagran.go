package lagran

import (
	"context"
	"regexp"
	"redi-multi/config"
	"time"

	"github.com/coreos/go-iptables/iptables"
	"github.com/florianl/go-nfqueue"
	"github.com/panjf2000/ants/v2"
	"github.com/sirupsen/logrus"
)

var portDigitRe = regexp.MustCompile(`\d+`)

const (
	windowSa uint16 = 1
	windowA  uint16 = 1
	windowPa uint16 = 1
	windowFa uint16 = 1
)

func Run() {
	if !Enabled() {
		logrus.Warn("[lagran] disabled (REDI_LAGRAN_DISABLED or lagran.enabled=false)")
		return
	}
	sport := config.LagranSport()
	setIptable(sport)
	startPool := func(label string, handler func(int), from, to int) bool {
		p, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			handler(i.(int))
		})
		if err != nil {
			logrus.Errorf("[lagran] %s pool: %v", label, err)
			return false
		}
		logrus.Debugf("[lagran] Starting Task %s", label)
		for i := from; i < to; i++ {
			_ = p.Invoke(i)
		}
		// NFQUEUE handlers block for process lifetime; do not Release() when Run returns.
		return true
	}
	if config.LagranBool("syn_ack", true) && !startPool("p1", packetHandle, 1000, 1128) {
		UnsetIptable(sport)
		return
	}
	if config.LagranBool("ack", true) && !startPool("p2", packetHandle, 2000, 2128) {
		UnsetIptable(sport)
		return
	}
	if config.LagranBool("psh_ack", true) && !startPool("p3", packetHandle, 3000, 3128) {
		UnsetIptable(sport)
		return
	}
	if config.LagranBool("fin_ack", true) && !startPool("p4", packetHandle, 4000, 4128) {
		UnsetIptable(sport)
		return
	}
}

func setIptable(sport string) {
	logrus.Debugf("[lagran] sport:%s", sport)
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] iptables new error:%v\n", err)
		return
	}
	appendLagranRules(ipt, sport, "iptables")

	ip6, err := iptables.New(iptables.IPFamily(iptables.ProtocolIPv6))
	if err != nil {
		logrus.Errorf("[lagran] ip6tables new error: %v", err)
	} else {
		appendLagranRules(ip6, sport, "ip6tables")
	}
}

func UnsetIptable(sport string) {
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] failed to new iptables, err: %v\n", err)
	} else {
		deleteLagranRules(ipt, sport, "iptables")
	}

	ip6, err := iptables.New(iptables.IPFamily(iptables.ProtocolIPv6))
	if err != nil {
		logrus.Errorf("[lagran] failed to new ip6tables, err: %v\n", err)
	} else {
		deleteLagranRules(ip6, sport, "ip6tables")
	}
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
