package lagran

import (
	"net"
	"strconv"
	"sync"
	"time"

	"redi-multi/config"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
)

const flowTTL = 10 * time.Second

func windowAfterSplit() uint16 {
	return clampWindow(config.LagranInt("window_after_split", config.DefaultWindowAfterSplit), config.DefaultWindowAfterSplit)
}

func windowConfusionLast() uint16 {
	return clampWindow(config.LagranInt("window_confusion_last", config.DefaultWindowConfusionLast), config.DefaultWindowConfusionLast)
}

func clampWindow(n, def int) uint16 {
	if n < 1 || n > 65535 {
		return uint16(def)
	}
	return uint16(n)
}

type flowStat struct {
	created time.Time
	count   int
}

var (
	flowsMu     sync.Mutex
	flowMap     = map[string]*flowStat{}
	nowFn       = time.Now
	janitorOnce sync.Once
)

// tryRewriteOutboundTCP 按 geneva.py 改出站 TCP。
// SYN+ACK：去掉 Window Scale，窗口写成该源端口的 lagran.window，并安排混淆 ACK。
// 纯 ACK：同一条流上计次小于 split_number 时用该窗口，否则写成 window_after_split（默认 28960）。
// PSH+ACK、FIN+ACK 不改。HTTP-01 暂停期间原样放过。
func tryRewriteOutboundTCP(raw []byte) ([]byte, bool) {
	if http01PauseActive() {
		return nil, false
	}
	if len(raw) < 1 {
		return nil, false
	}
	switch raw[0] >> 4 {
	case 4:
		return rewriteIPPacket(raw, layers.LayerTypeIPv4)
	case 6:
		if len(raw) < 40 {
			return nil, false
		}
		if raw[6] != byte(layers.IPProtocolTCP) {
			return nil, false
		}
		return rewriteIPPacket(raw, layers.LayerTypeIPv6)
	default:
		return nil, false
	}
}

func rewriteIPPacket(raw []byte, decode gopacket.LayerType) ([]byte, bool) {
	packet := gopacket.NewPacket(raw, decode, gopacket.Default)
	tcpLayer := packet.Layer(layers.LayerTypeTCP)
	if tcpLayer == nil {
		return nil, false
	}
	tcp, ok := tcpLayer.(*layers.TCP)
	if !ok || tcp == nil {
		return nil, false
	}
	sport := portDigitRe.FindString(tcp.SrcPort.String())
	if sport == "" || !config.LagranPortSet()[sport] {
		return nil, false
	}
	synAck := isSYNACK(tcp)
	pureAck := isPureACK(tcp)
	if synAck && !config.LagranBool("syn_ack", true) {
		return nil, false
	}
	if pureAck && !config.LagranBool("ack", true) {
		return nil, false
	}
	if !synAck && !pureAck {
		return nil, false
	}
	server, client, v6, ok := packetIPs(packet)
	if !ok {
		return nil, false
	}
	window := config.LagranWindow(sport)
	key := flowKey(client, tcp.SrcPort, tcp.DstPort)
	now := nowFn()
	if synAck {
		noteHandshake(key, now)
		stripWindowScale(tcp)
	} else {
		window = takeACKWindow(key, now, window)
	}
	tcp.Window = window
	if err := tcp.SetNetworkLayerForChecksum(packet.NetworkLayer()); err != nil {
		return nil, false
	}
	buffer := gopacket.NewSerializeBuffer()
	options := gopacket.SerializeOptions{
		ComputeChecksums: true,
		FixLengths:       true,
	}
	if err := gopacket.SerializePacket(buffer, options, packet); err != nil {
		return nil, false
	}
	if synAck {
		pkts := buildConfusionPackets(server, client, v6, tcp, sport)
		if len(pkts) > 0 {
			// 混淆包的目的地址是本机（原包的源），与 geneva.py send_payloads 一致。
			dispatchConfusion(pkts, append(net.IP(nil), server...))
		}
	}
	return buffer.Bytes(), true
}

func isSYNACK(tcp *layers.TCP) bool {
	return tcp.SYN && tcp.ACK && !tcp.PSH && !tcp.FIN && !tcp.RST && !tcp.URG
}

func isPureACK(tcp *layers.TCP) bool {
	return tcp.ACK && !tcp.SYN && !tcp.PSH && !tcp.FIN && !tcp.RST && !tcp.URG
}

func packetIPs(packet gopacket.Packet) (server, client net.IP, v6 bool, ok bool) {
	if layer := packet.Layer(layers.LayerTypeIPv4); layer != nil {
		ip, cast := layer.(*layers.IPv4)
		if !cast || ip == nil || ip.SrcIP == nil || ip.DstIP == nil {
			return nil, nil, false, false
		}
		return ip.SrcIP, ip.DstIP, false, true
	}
	if layer := packet.Layer(layers.LayerTypeIPv6); layer != nil {
		ip, cast := layer.(*layers.IPv6)
		if !cast || ip == nil || ip.SrcIP == nil || ip.DstIP == nil {
			return nil, nil, true, false
		}
		return ip.SrcIP, ip.DstIP, true, true
	}
	return nil, nil, false, false
}

// flowKey 用客户端地址、客户端端口和本机源端口区分连接。
func flowKey(client net.IP, serverPort, clientPort layers.TCPPort) string {
	return client.String() + "_" + strconv.Itoa(int(clientPort)) + "_" + strconv.Itoa(int(serverPort))
}

func stripWindowScale(tcp *layers.TCP) {
	kept := make([]layers.TCPOption, 0, len(tcp.Options))
	for _, op := range tcp.Options {
		switch op.OptionType {
		case layers.TCPOptionKindWindowScale, layers.TCPOptionKindEndList:
			continue
		default:
			kept = append(kept, op)
		}
	}
	tcp.Options = kept
	tcp.Padding = nil
}

func noteHandshake(key string, now time.Time) {
	flowsMu.Lock()
	defer flowsMu.Unlock()
	flowMap[key] = &flowStat{created: now, count: 1}
}

func takeACKWindow(key string, now time.Time, small uint16) uint16 {
	split := config.LagranInt("split_number", config.DefaultSplitNumber)
	flowsMu.Lock()
	defer flowsMu.Unlock()
	st := flowMap[key]
	if st == nil || now.Sub(st.created) >= flowTTL {
		st = &flowStat{created: now, count: 1}
		flowMap[key] = st
	}
	window := small
	if st.count >= split {
		window = windowAfterSplit()
	}
	st.count++
	return window
}

func expireFlows(now time.Time) {
	flowsMu.Lock()
	defer flowsMu.Unlock()
	for key, st := range flowMap {
		if now.Sub(st.created) >= flowTTL {
			delete(flowMap, key)
		}
	}
}

func startFlowJanitor() {
	janitorOnce.Do(func() {
		go func() {
			ticker := time.NewTicker(flowTTL)
			defer ticker.Stop()
			for now := range ticker.C {
				expireFlows(now)
			}
		}()
	})
}

func resetFlows() {
	flowsMu.Lock()
	flowMap = map[string]*flowStat{}
	flowsMu.Unlock()
}
