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
	if !setIptable(sport) {
		logrus.Error("[lagran] iptables OUTPUT rules were not installed; aborting lagran (check cap_net_admin / root)")
		return
	}
	type queuePool struct {
		label   string
		from    int
		to      int
		boolKey string
	}
	specs := []queuePool{
		{label: "p1", from: 1000, to: 1128, boolKey: "syn_ack"},
		{label: "p2", from: 2000, to: 2128, boolKey: "ack"},
		{label: "p3", from: 3000, to: 3128, boolKey: "psh_ack"},
		{label: "p4", from: 4000, to: 4128, boolKey: "fin_ack"},
	}
	var pools []*ants.PoolWithFunc
	for _, spec := range specs {
		if !config.LagranBool(spec.boolKey, true) {
			continue
		}
		p, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
		})
		if err != nil {
			logrus.Errorf("[lagran] %s pool: %v (aborting lagran startup; no iptables rules)", spec.label, err)
			UnsetIptable(sport)
			return
		}
		pools = append(pools, p)
	}
	poolIdx := 0
	for _, spec := range specs {
		if !config.LagranBool(spec.boolKey, true) {
			continue
		}
		p := pools[poolIdx]
		poolIdx++
		logrus.Debugf("[lagran] Starting Task %s", spec.label)
		for i := spec.from; i < spec.to; i++ {
			_ = p.Invoke(i)
		}
		// NFQUEUE handlers block for process lifetime; do not Release() when Run returns.
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
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] failed to new iptables, err: %v\n", err)
	} else {
		deleteLagranRules(ipt, sport, "iptables")
	}

	ip6, err := iptables.New(iptables.IPFamily(iptables.ProtocolIPv6))
	if err != nil {
		logrus.Warnf("[lagran] ip6tables new error during cleanup: %v", err)
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
