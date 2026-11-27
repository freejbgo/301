package lagran

import (
	"encoding/binary"
	"testing"
	"time"

	"redi-multi/config"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
)

func setupLagranPortsForTest() {
	ResumeHTTP01Window()
	config.Lagran = config.DefaultLagran()
	config.Lagran["port"] = "80,443"
}

func buildIPv6TCP(syn, ack, psh bool, window uint16, withMSS bool, payload []byte) []byte {
	ip := &layers.IPv6{
		Version:      6,
		TrafficClass: 0x2e,
		FlowLabel:    0x12345,
		NextHeader:   layers.IPProtocolTCP,
		HopLimit:     64,
		SrcIP:        []byte{0x20, 0x01, 0x0d, 0xb8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1},
		DstIP:        []byte{0x20, 0x01, 0x0d, 0xb8, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2},
	}
	tcp := &layers.TCP{
		SrcPort: layers.TCPPort(80),
		DstPort: layers.TCPPort(40000),
		SYN:     syn,
		ACK:     ack,
		PSH:     psh,
		Window:  window,
		Seq:     0x11223344,
		Ack:     0x55667788,
	}
	if withMSS {
		tcp.Options = []layers.TCPOption{
			{OptionType: layers.TCPOptionKindMSS, OptionLength: 4, OptionData: []byte{0x05, 0xb4}},
		}
	}
	_ = tcp.SetNetworkLayerForChecksum(ip)
	buf := gopacket.NewSerializeBuffer()
	opts := gopacket.SerializeOptions{ComputeChecksums: true, FixLengths: true}
	var err error
	if payload == nil {
		err = gopacket.SerializeLayers(buf, opts, ip, tcp)
	} else {
		err = gopacket.SerializeLayers(buf, opts, ip, tcp, gopacket.Payload(payload))
	}
	if err != nil {
		panic(err)
	}
	return buf.Bytes()
}

func buildIPv4TCP(syn, ack bool, window uint16) []byte {
	ip := &layers.IPv4{
		Version:  4,
		IHL:      5,
		TTL:      64,
		Protocol: layers.IPProtocolTCP,
		SrcIP:    netIPv4(203, 0, 113, 1),
		DstIP:    netIPv4(203, 0, 113, 2),
	}
	tcp := &layers.TCP{
		SrcPort: layers.TCPPort(80),
		DstPort: layers.TCPPort(40000),
		SYN:     syn,
		ACK:     ack,
		Window:  window,
		Seq:     1,
		Ack:     1,
	}
	_ = tcp.SetNetworkLayerForChecksum(ip)
	buf := gopacket.NewSerializeBuffer()
	opts := gopacket.SerializeOptions{ComputeChecksums: true, FixLengths: true}
	if err := gopacket.SerializeLayers(buf, opts, ip, tcp); err != nil {
		panic(err)
	}
	return buf.Bytes()
}

func netIPv4(a, b, c, d byte) []byte {
	return []byte{a, b, c, d}
}

func ipv6TCPChecksumIndependent(full []byte) uint16 {
	tcpSeg := full[40:]
	tcpSeg = append([]byte(nil), tcpSeg...)
	tcpSeg[16], tcpSeg[17] = 0, 0
	var sum uint32
	for i := 0; i < 16; i += 2 {
		sum += uint32(full[8+i])<<8 | uint32(full[9+i])
		sum += uint32(full[24+i])<<8 | uint32(full[25+i])
	}
	length := uint32(len(tcpSeg))
	sum += 6
	sum += length & 0xffff
	sum += length >> 16
	for i := 0; i+1 < len(tcpSeg); i += 2 {
		sum += uint32(tcpSeg[i])<<8 | uint32(tcpSeg[i+1])
	}
	if len(tcpSeg)%2 == 1 {
		sum += uint32(tcpSeg[len(tcpSeg)-1]) << 8
	}
	for sum > 0xffff {
		sum = (sum >> 16) + (sum & 0xffff)
	}
	return ^uint16(sum)
}

func TestIPv6SYNACKWithMSS(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCP(true, true, false, 65535, true, nil)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expected rewrite")
	}
	if len(out) != len(raw) {
		t.Fatalf("length changed %d -> %d", len(raw), len(out))
	}
	pkt := gopacket.NewPacket(out, layers.LayerTypeIPv6, gopacket.Default)
	tcp := pkt.Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != windowSa {
		t.Fatalf("window %d", tcp.Window)
	}
	if len(tcp.Options) != 1 || tcp.Options[0].OptionType != layers.TCPOptionKindMSS {
		t.Fatal("MSS option missing")
	}
	got := binary.BigEndian.Uint16(out[56:58])
	want := ipv6TCPChecksumIndependent(out)
	if got != want {
		t.Fatalf("checksum got %04x want %04x", got, want)
	}
}

func TestIPv6ACKNoPayload(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCP(false, true, false, 65535, false, nil)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok || len(out) != 60 {
		t.Fatalf("ok=%v len=%d", ok, len(out))
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv6, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != windowA {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestIPv6PSHACKWithPayload(t *testing.T) {
	setupLagranPortsForTest()
	payload := []byte("hello-v6")
	raw := buildIPv6TCP(false, true, true, 65535, true, payload)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok || len(out) != len(raw) {
		t.Fatalf("ok=%v len %d -> %d", ok, len(raw), len(out))
	}
	if string(out[len(out)-len(payload):]) != string(payload) {
		t.Fatal("payload changed")
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv6, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != windowPa {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestIPv6HopByHopUnchanged(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCP(true, true, false, 65535, false, nil)
	raw[6] = byte(layers.IPProtocolIPv6HopByHop)
	_, ok := tryRewriteOutboundTCP(raw)
	if ok {
		t.Fatal("hop-by-hop must not rewrite")
	}
}

func TestIPv6BareSYNUnchanged(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCP(true, false, false, 65535, false, nil)
	_, ok := tryRewriteOutboundTCP(raw)
	if ok {
		t.Fatal("bare SYN must not rewrite")
	}
}

func TestHTTP01PauseLeavesWindowUnchanged(t *testing.T) {
	setupLagranPortsForTest()
	PauseHTTP01Window()
	t.Cleanup(ResumeHTTP01Window)
	raw := buildIPv4TCP(true, true, 65535)
	if _, ok := tryRewriteOutboundTCP(raw); ok {
		t.Fatal("HTTP-01 pause must pass the original TCP window")
	}
}

func TestHTTP01PauseExpires(t *testing.T) {
	setupLagranPortsForTest()
	PauseHTTP01Window()
	t.Cleanup(ResumeHTTP01Window)
	http01PauseMu.Lock()
	http01PausedAt = time.Now().Add(-http01PauseMax - time.Second)
	http01PauseMu.Unlock()
	raw := buildIPv4TCP(true, true, 65535)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expired HTTP-01 pause must rewrite again")
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != windowSa {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestIPv4StillRewritten(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv4TCP(true, true, 65535)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expected ipv4 rewrite")
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != windowSa {
		t.Fatalf("window %d", tcp.Window)
	}
}
