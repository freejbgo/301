package lagran

import (
	"redi-multi/config"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
)

// tryRewriteOutboundTCP 改出站 TCP 窗口。不依赖 NFQUEUE。
// 返回改好的完整 IP 包（从 IP 头开始）。失败时返回 false，调用方应原样接受。
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
	sportSet := config.LagranPortSet()
	sport := portDigitRe.FindString(tcp.SrcPort.String())
	if sport == "" || !sportSet[sport] {
		return nil, false
	}
	windowSize, match := lagranWindowForTCP(sport, tcp)
	if !match {
		return nil, false
	}
	tcp.Window = windowSize
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
	out := buffer.Bytes()
	if len(out) != len(raw) {
		return nil, false
	}
	return out, true
}

func lagranWindowForTCP(sport string, tcp *layers.TCP) (uint16, bool) {
	ok1 := config.LagranBool("syn_ack", true) && tcp.SYN && tcp.ACK
	ok2 := config.LagranBool("ack", true) && tcp.ACK && !tcp.PSH && !tcp.FIN && !tcp.SYN && !tcp.RST
	ok3 := config.LagranBool("psh_ack", true) && tcp.PSH && tcp.ACK
	ok4 := config.LagranBool("fin_ack", true) && tcp.FIN && tcp.ACK
	if !(ok1 || ok2 || ok3 || ok4) {
		return 0, false
	}
	return config.LagranWindow(sport), true
}
