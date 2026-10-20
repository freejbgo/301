package lagran

import (
	"context"
	"regexp"
	"redi-multi/config"
	"sync"
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
	setIptable(config.LagranSport())
	var wg sync.WaitGroup
	if config.LagranBool("syn_ack", true) {
		p1, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		if err != nil {
			logrus.Errorf("[lagran] syn_ack pool: %v", err)
			return
		}
		defer p1.Release()
		logrus.Debugln("[lagran] Starting Task p1")
		for i := 1000; i < 1128; i++ {
			wg.Add(1)
			_ = p1.Invoke(int(i))
		}
	}
	if config.LagranBool("ack", true) {
		p2, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		if err != nil {
			logrus.Errorf("[lagran] ack pool: %v", err)
			return
		}
		defer p2.Release()
		logrus.Debugln("[lagran] Starting Task p2")
		for i := 2000; i < 2128; i++ {
			wg.Add(1)
			_ = p2.Invoke(int(i))
		}
	}
	if config.LagranBool("psh_ack", true) {
		p3, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		if err != nil {
			logrus.Errorf("[lagran] psh_ack pool: %v", err)
			return
		}
		defer p3.Release()
		logrus.Debugln("[lagran] Starting Task p3")
		for i := 3000; i < 3128; i++ {
			wg.Add(1)
			_ = p3.Invoke(int(i))
		}
	}
	if config.LagranBool("fin_ack", true) {
		p4, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		if err != nil {
			logrus.Errorf("[lagran] fin_ack pool: %v", err)
			return
		}
		defer p4.Release()
		logrus.Debugln("[lagran] Starting Task p4")
		for i := 4000; i < 4128; i++ {
			wg.Add(1)
			_ = p4.Invoke(int(i))
		}
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
		return
	}
	appendLagranRules(ip6, sport, "ip6tables")
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
		return
	}
	deleteLagranRules(ip6, sport, "ip6tables")
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
