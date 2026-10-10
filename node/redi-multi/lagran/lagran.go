package lagran

import (
	"context"
	"net"
	"redi-multi/config"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/coreos/go-iptables/iptables"
	"github.com/florianl/go-nfqueue"
	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
	"github.com/panjf2000/ants/v2"
	"github.com/sirupsen/logrus"
)

const (
	windowSa uint16 = 1
	windowA  uint16 = 1
	windowPa uint16 = 1
	windowFa uint16 = 1
)

func Run() {
	setIptable(config.Lagran["port"].(string))
	var wg sync.WaitGroup
	if config.Lagran["syn_ack"].(bool) {
		p1, _ := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		defer p1.Release()
		logrus.Debugln("[lagran] Starting Task p1")
		for i := 1000; i < 1128; i++ {
			wg.Add(1)
			_ = p1.Invoke(int(i))
		}
	}
	if config.Lagran["ack"].(bool) {
		p2, _ := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		defer p2.Release()
		logrus.Debugln("[lagran] Starting Task p2")
		for i := 2000; i < 2128; i++ {
			wg.Add(1)
			_ = p2.Invoke(int(i))
		}
	}
	if config.Lagran["psh_ack"].(bool) {
		p3, _ := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		defer p3.Release()
		logrus.Debugln("[lagran] Starting Task p3")
		for i := 3000; i < 3128; i++ {
			wg.Add(1)
			_ = p3.Invoke(int(i))
		}
	}
	if config.Lagran["fin_ack"].(bool) {
		p4, _ := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
			wg.Done()
		})
		defer p4.Release()
		logrus.Debugln("[lagran] Starting Task p4")
		for i := 4000; i < 4128; i++ {
			wg.Add(1)
			_ = p4.Invoke(int(i))
		}
	}
}

func setIptable(sport string) {
	logrus.Errorf("[lagran] sport:%s\n", sport)
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] Iptabels new error:%v\n", err)
	}

	logrus.Infoln("⇨ starting to clear iptables OUTPUT chain.")
	if err := ipt.ClearChain("filter", "OUTPUT"); err != nil {
		logrus.Errorln("[lagran] failed to clear iptables OUTPUT chain.")
	}
	if config.Lagran["syn_ack"].(bool) {
		err = ipt.AppendUnique("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "SYN,ACK", "-j", "NFQUEUE", "--queue-balance", "1000:1127")
		if err != nil {
			logrus.Errorf("[lagran] failed to set iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["ack"].(bool) {
		err = ipt.AppendUnique("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "ACK", "-j", "NFQUEUE", "--queue-balance", "2000:2127")
		if err != nil {
			logrus.Errorf("[lagran] failed to set iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["psh_ack"].(bool) {
		err = ipt.AppendUnique("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "PSH,ACK", "-j", "NFQUEUE", "--queue-balance", "3000:3127")
		if err != nil {
			logrus.Errorf("[lagran] failed to set iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["fin_ack"].(bool) {
		err = ipt.AppendUnique("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "FIN,ACK", "-j", "NFQUEUE", "--queue-balance", "4000:4127")
		if err != nil {
			logrus.Errorf("[lagran] failed to set iptables OUTPUT chain, err: %v\n", err)
		}
	}
}
func UnsetIptable(sport string) {
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] failed to new iptabels, err: %v\n", err)
	}
	if config.Lagran["syn_ack"].(bool) {
		err = ipt.Delete("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "SYN,ACK", "-j", "NFQUEUE", "--queue-balance", "1000:1127")
		if err != nil {
			logrus.Errorf("[lagran] failed to delete iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["ack"].(bool) {
		err = ipt.Delete("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "ACK", "-j", "NFQUEUE", "--queue-balance", "2000:2127")
		if err != nil {
			logrus.Errorf("[lagran] failed to delete iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["psh_ack"].(bool) {
		err = ipt.Delete("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "PSH,ACK", "-j", "NFQUEUE", "--queue-balance", "3000:3127")
		if err != nil {
			logrus.Errorf("[lagran] failed to delete iptables OUTPUT chain, err: %v\n", err)
		}
	}
	if config.Lagran["fin_ack"].(bool) {
		err = ipt.Delete("filter", "OUTPUT", "-p", "tcp", "-m", "multiport", "--sport", sport, "--tcp-flags", "SYN,RST,ACK,FIN,PSH", "FIN,ACK", "-j", "NFQUEUE", "--queue-balance", "4000:4127")
		if err != nil {
			logrus.Errorf("[lagran] failed to delete iptables OUTPUT chain, err: %v\n", err)
		}
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
		logrus.Fatalf("[lagran] failed to open nfqueue socket, err:%v\n", err)
	}

	defer nf.Close()

	ctx, cancelFunc := context.WithCancel(context.Background())
	defer cancelFunc()

	fn := func(a nfqueue.Attribute) int {
		id := *a.PacketID
		var srcIP net.IP
		var dstIP net.IP
		logrus.Debugf("[lagran] PKT[%03d]\t\n", id)

		packet := gopacket.NewPacket(*a.Payload, layers.LayerTypeIPv4, gopacket.Default)

		// Get the IP layer from this packet
		if ipLayer := packet.Layer(layers.LayerTypeIPv4); ipLayer != nil {
			// Get actual IP data from this layer
			ip, _ := ipLayer.(*layers.IPv4)
			srcIP = ip.SrcIP
			dstIP = ip.DstIP
			logrus.Debugf("[lagran] %15s > %-15s \n", srcIP, dstIP)
		}
		if tcpLayer := packet.Layer(layers.LayerTypeTCP); tcpLayer != nil {
			tcp, _ := tcpLayer.(*layers.TCP)
			ports := strings.Split(config.Lagran["port"].(string), ",")
			reg := regexp.MustCompile(`\d+`)
			sport := reg.FindString(tcp.SrcPort.String())
			var matchedPort bool = false

			for _, port := range ports {
				if port == sport {
					matchedPort = true
					break
				}
			}
			if matchedPort {
				var ok1 bool = config.Lagran["syn_ack"].(bool) && tcp.SYN && tcp.ACK
				var ok2 bool = config.Lagran["ack"].(bool) && tcp.ACK && !tcp.PSH && !tcp.FIN && !tcp.SYN && !tcp.RST
				var ok3 bool = config.Lagran["psh_ack"].(bool) && tcp.PSH && tcp.ACK
				var ok4 bool = config.Lagran["fin_ack"].(bool) && tcp.FIN && tcp.ACK
				var windowSize uint16
				if ok1 || ok2 || ok3 || ok4 {
					if ok1 {
						windowSize = windowSa
						logrus.Debugln("[lagran] Handle SYN=1 and ACK=1")
					}
					if ok2 {
						windowSize = windowA
						logrus.Debugln("[lagran] Handle ACK=1")
					}
					if ok3 {
						windowSize = windowPa
						logrus.Debugln("[lagran] Handle PSH=1 and ACK=1")
					}
					if ok4 {
						windowSize = windowFa
						logrus.Debugln("[lagran] Handle FIN=1 and ACK=1")
					}
					packet.TransportLayer().(*layers.TCP).Window = windowSize
					err := packet.TransportLayer().(*layers.TCP).SetNetworkLayerForChecksum(packet.NetworkLayer())
					if err != nil {
						logrus.Errorf("[lagran] failed to SetNetworkLayerForChecksum, err: %v\n", err)
					}
					buffer := gopacket.NewSerializeBuffer()
					options := gopacket.SerializeOptions{
						ComputeChecksums: true,
						FixLengths:       true,
					}
					if err := gopacket.SerializePacket(buffer, options, packet); err != nil {
						logrus.Errorf("[lagran] failed to SerializePacket, err: %v\n", err)
					}
					packetBytes := buffer.Bytes()
					logrus.Debugf("[lagran] Set TCP window size to %d\n", windowSize)
					err = nf.SetVerdictModPacket(id, nfqueue.NfAccept, packetBytes)
					if err != nil {
						logrus.Errorf("[lagran] failed to SetVerdictModified error: %v\n", err)
					}
					return 0
				}
				err := nf.SetVerdict(id, nfqueue.NfAccept)
				if err != nil {
					logrus.Errorf("[lagran] failed to SetVerdict, err: %v\n", err)
				}
				return 0
			}
			err := nf.SetVerdict(id, nfqueue.NfAccept)
			if err != nil {
				logrus.Errorf("[lagran] failed to SetVerdict, err: %v\n", err)
			}
			return 0
		}
		err := nf.SetVerdict(id, nfqueue.NfAccept)
		if err != nil {
			logrus.Errorf("[lagran] failed to SetVerdict, err: %v\n", err)
		}
		return 0
	}

	// Register your function to listen on nflqueue queue 100
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
