package lagran

import (
	"encoding/binary"
	"net"
	"testing"
	"time"

	"redi-multi/config"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
)

func setupLagranPortsForTest() {
	ResumeHTTP01Window()
	resetFlows()
	nowFn = time.Now
	dispatchConfusion = func([][]byte, net.IP) {}
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
	return buildIPv4TCPFrom(80, syn, ack, window)
}

func buildIPv4TCPFrom(src int, syn, ack bool, window uint16) []byte {
	ip := &layers.IPv4{
		Version:  4,
		IHL:      5,
		TTL:      64,
		Protocol: layers.IPProtocolTCP,
		SrcIP:    netIPv4(203, 0, 113, 1),
		DstIP:    netIPv4(203, 0, 113, 2),
	}
	tcp := &layers.TCP{
		SrcPort: layers.TCPPort(src),
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
	if tcp.Window != config.LagranWindow("80") {
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
	if tcp.Window != config.LagranWindow("80") {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestIPv6PSHACKWithPayload(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCP(false, true, true, 65535, true, []byte("hello-v6"))
	if _, ok := tryRewriteOutboundTCP(raw); ok {
		t.Fatal("PSH+ACK must pass unchanged")
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
	if tcp.Window != config.LagranWindow("80") {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestWindowFollowsSourcePort(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["window"] = map[string]uint16{
		"80":  128,
		"443": 1024,
	}
	out80, ok := tryRewriteOutboundTCP(buildIPv4TCPFrom(80, true, true, 65535))
	if !ok {
		t.Fatal("expected port 80 rewrite")
	}
	tcp80 := gopacket.NewPacket(out80, layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp80.Window != 128 {
		t.Fatalf("port 80 window %d", tcp80.Window)
	}
	out443, ok := tryRewriteOutboundTCP(buildIPv4TCPFrom(443, true, true, 65535))
	if !ok {
		t.Fatal("expected port 443 rewrite")
	}
	tcp443 := gopacket.NewPacket(out443, layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp443.Window != 1024 {
		t.Fatalf("port 443 window %d", tcp443.Window)
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
	if tcp.Window != config.LagranWindow("80") {
		t.Fatalf("window %d", tcp.Window)
	}
}

func TestFINACKUnchanged(t *testing.T) {
	setupLagranPortsForTest()
	ip := &layers.IPv4{
		Version:  4,
		IHL:      5,
		TTL:      64,
		Protocol: layers.IPProtocolTCP,
		SrcIP:    netIPv4(203, 0, 113, 1),
		DstIP:    netIPv4(203, 0, 113, 2),
	}
	tcp := &layers.TCP{
		SrcPort: 80,
		DstPort: 40000,
		ACK:     true,
		FIN:     true,
		Window:  65535,
	}
	_ = tcp.SetNetworkLayerForChecksum(ip)
	buf := gopacket.NewSerializeBuffer()
	opts := gopacket.SerializeOptions{ComputeChecksums: true, FixLengths: true}
	if err := gopacket.SerializeLayers(buf, opts, ip, tcp); err != nil {
		t.Fatal(err)
	}
	if _, ok := tryRewriteOutboundTCP(buf.Bytes()); ok {
		t.Fatal("FIN+ACK must pass unchanged")
	}
}

func TestSYNACKStripsWindowScale(t *testing.T) {
	setupLagranPortsForTest()
	raw := buildIPv6TCPOpts(true, true, false, 65535, []layers.TCPOption{
		{OptionType: layers.TCPOptionKindMSS, OptionLength: 4, OptionData: []byte{0x05, 0xb4}},
		{OptionType: layers.TCPOptionKindWindowScale, OptionData: []byte{7}},
	}, nil)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expected rewrite")
	}
	if len(out) >= len(raw) {
		t.Fatalf("expected header to shrink, %d -> %d", len(raw), len(out))
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv6, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != config.LagranWindow("80") {
		t.Fatalf("window %d", tcp.Window)
	}
	if len(tcp.Options) != 1 || tcp.Options[0].OptionType != layers.TCPOptionKindMSS {
		t.Fatalf("options %#v", tcp.Options)
	}
	got := binary.BigEndian.Uint16(out[56:58])
	want := ipv6TCPChecksumIndependent(out)
	if got != want {
		t.Fatalf("checksum got %04x want %04x", got, want)
	}
}

func TestSYNACKKeepsPayload(t *testing.T) {
	setupLagranPortsForTest()
	payload := []byte("xyz")
	raw := buildIPv6TCP(true, true, false, 65535, false, payload)
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expected rewrite")
	}
	if string(out[len(out)-len(payload):]) != string(payload) {
		t.Fatal("payload dropped")
	}
}

func TestACKWindowStepsAndHandshakeReset(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["split_number"] = 2
	config.Lagran["window"] = map[string]uint16{"80": 17}
	raw := buildIPv4TCP(false, true, 65535)
	if got := rewrittenWindow(t, raw); got != 17 {
		t.Fatalf("first ack window %d", got)
	}
	if got := rewrittenWindow(t, raw); got != windowAfterSplit() {
		t.Fatalf("second ack window %d", got)
	}
	syn := buildIPv4TCP(true, true, 65535)
	if got := rewrittenWindow(t, syn); got != 17 {
		t.Fatalf("syn-ack window %d", got)
	}
	if got := rewrittenWindow(t, raw); got != 17 {
		t.Fatalf("ack after new handshake window %d", got)
	}
}

func TestACKUsesConfiguredWindowAfterSplit(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["split_number"] = 1
	config.Lagran["window_after_split"] = 1000
	config.Lagran["window"] = map[string]uint16{"80": 17}
	raw := buildIPv4TCP(false, true, 65535)
	if got := rewrittenWindow(t, raw); got != 1000 {
		t.Fatalf("after-split window %d", got)
	}
}

func TestConfusionUsesConfiguredLastWindow(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["confusion_times"] = 1
	config.Lagran["window_confusion_last"] = 2000
	config.Lagran["window"] = map[string]uint16{"80": 17}
	var got [][]byte
	dispatchConfusion = func(pkts [][]byte, dst net.IP) {
		got = pkts
	}
	raw := buildIPv4TCP(true, true, 65535)
	if _, ok := tryRewriteOutboundTCP(raw); !ok {
		t.Fatal("expected rewrite")
	}
	if len(got) != 1 {
		t.Fatalf("packets %d", len(got))
	}
	tcp := gopacket.NewPacket(got[0], layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	if tcp.Window != 2000 {
		t.Fatalf("last confusion window %d", tcp.Window)
	}
}

func TestFlowExpiresWithoutRefreshingOnACK(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["split_number"] = 2
	config.Lagran["window"] = map[string]uint16{"80": 17}
	start := time.Unix(1_700_000_000, 0)
	nowFn = func() time.Time { return start }
	t.Cleanup(func() { nowFn = time.Now })
	raw := buildIPv4TCP(false, true, 65535)
	if got := rewrittenWindow(t, raw); got != 17 {
		t.Fatalf("first window %d", got)
	}
	nowFn = func() time.Time { return start.Add(9 * time.Second) }
	if got := rewrittenWindow(t, raw); got != windowAfterSplit() {
		t.Fatalf("window before expiry %d", got)
	}
	nowFn = func() time.Time { return start.Add(flowTTL) }
	if got := rewrittenWindow(t, raw); got != 17 {
		t.Fatalf("window after expiry %d", got)
	}
}

func TestConfusionPacketsFollowGeneva(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["confusion_times"] = 2
	config.Lagran["window_scale"] = 7
	config.Lagran["window"] = map[string]uint16{"80": 128}
	var got [][]byte
	var gotDst net.IP
	dispatchConfusion = func(pkts [][]byte, dst net.IP) {
		got = pkts
		gotDst = append(net.IP(nil), dst...)
	}
	raw := buildIPv4TCP(true, true, 65535)
	if _, ok := tryRewriteOutboundTCP(raw); !ok {
		t.Fatal("expected rewrite")
	}
	if !gotDst.Equal(netIPv4(203, 0, 113, 1)) {
		t.Fatalf("inject dst %v", gotDst)
	}
	if len(got) != 2 {
		t.Fatalf("packets %d", len(got))
	}
	assertConfusion(t, got[0], netIPv4(203, 0, 113, 2), netIPv4(203, 0, 113, 1), 2, 128, 7)
	assertConfusion(t, got[1], netIPv4(203, 0, 113, 2), netIPv4(203, 0, 113, 1), 3, windowConfusionLast(), 7)
}

func TestIPv6ConfusionUsesLastWindowWhenSingle(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["confusion_times"] = 1
	var got [][]byte
	dispatchConfusion = func(pkts [][]byte, dst net.IP) {
		got = pkts
	}
	raw := buildIPv6TCP(true, true, false, 65535, false, nil)
	if _, ok := tryRewriteOutboundTCP(raw); !ok {
		t.Fatal("expected rewrite")
	}
	if len(got) != 1 {
		t.Fatalf("packets %d", len(got))
	}
	pkt := gopacket.NewPacket(got[0], layers.LayerTypeIPv6, gopacket.Default)
	ip := pkt.Layer(layers.LayerTypeIPv6).(*layers.IPv6)
	tcp := pkt.Layer(layers.LayerTypeTCP).(*layers.TCP)
	if !ip.SrcIP.Equal(net.ParseIP("2001:db8::2")) || !ip.DstIP.Equal(net.ParseIP("2001:db8::1")) {
		t.Fatalf("src %v dst %v", ip.SrcIP, ip.DstIP)
	}
	if tcp.Ack != 0x11223345 || tcp.Window != windowConfusionLast() || tcp.Seq != 0 || !tcp.ACK || tcp.SYN {
		t.Fatalf("tcp ack=%x win=%d seq=%d syn=%v ackflag=%v", tcp.Ack, tcp.Window, tcp.Seq, tcp.SYN, tcp.ACK)
	}
}

func TestConfusionDisabledSendsNothing(t *testing.T) {
	setupLagranPortsForTest()
	config.Lagran["confusion_times"] = 0
	called := false
	dispatchConfusion = func([][]byte, net.IP) { called = true }
	if _, ok := tryRewriteOutboundTCP(buildIPv4TCP(true, true, 65535)); !ok {
		t.Fatal("expected rewrite")
	}
	if called {
		t.Fatal("confusion_times 0 must not inject")
	}
}

func TestHTTP01PauseDoesNotInject(t *testing.T) {
	setupLagranPortsForTest()
	PauseHTTP01Window()
	t.Cleanup(ResumeHTTP01Window)
	dispatchConfusion = func([][]byte, net.IP) {
		t.Fatal("paused lagran must not inject")
	}
	if _, ok := tryRewriteOutboundTCP(buildIPv4TCP(true, true, 65535)); ok {
		t.Fatal("paused lagran must not rewrite")
	}
}

func TestPureACKDoesNotInject(t *testing.T) {
	setupLagranPortsForTest()
	dispatchConfusion = func([][]byte, net.IP) {
		t.Fatal("pure ACK must not inject")
	}
	if _, ok := tryRewriteOutboundTCP(buildIPv4TCP(false, true, 65535)); !ok {
		t.Fatal("expected ack rewrite")
	}
}

func rewrittenWindow(t *testing.T, raw []byte) uint16 {
	t.Helper()
	out, ok := tryRewriteOutboundTCP(raw)
	if !ok {
		t.Fatal("expected rewrite")
	}
	tcp := gopacket.NewPacket(out, layers.LayerTypeIPv4, gopacket.Default).Layer(layers.LayerTypeTCP).(*layers.TCP)
	return tcp.Window
}

func assertConfusion(t *testing.T, raw []byte, src, dst net.IP, ack uint32, window uint16, scale byte) {
	t.Helper()
	pkt := gopacket.NewPacket(raw, layers.LayerTypeIPv4, gopacket.Default)
	ip := pkt.Layer(layers.LayerTypeIPv4).(*layers.IPv4)
	tcp := pkt.Layer(layers.LayerTypeTCP).(*layers.TCP)
	if !ip.SrcIP.Equal(src) || !ip.DstIP.Equal(dst) {
		t.Fatalf("ip src %v dst %v", ip.SrcIP, ip.DstIP)
	}
	if tcp.SrcPort != 40000 || tcp.DstPort != 80 || tcp.Seq != 0 || tcp.Ack != ack || tcp.Window != window {
		t.Fatalf("tcp sport %d dport %d seq %d ack %d win %d", tcp.SrcPort, tcp.DstPort, tcp.Seq, tcp.Ack, tcp.Window)
	}
	if !tcp.ACK || tcp.SYN || tcp.PSH || tcp.FIN || tcp.RST {
		t.Fatal("flags")
	}
	if len(tcp.Options) != 6 || tcp.Options[0].OptionType != layers.TCPOptionKindWindowScale || len(tcp.Options[0].OptionData) != 1 || tcp.Options[0].OptionData[0] != scale {
		t.Fatalf("wscale %#v", tcp.Options)
	}
	for i := 1; i < 6; i++ {
		if tcp.Options[i].OptionType != layers.TCPOptionKindNop {
			t.Fatalf("option %d %#v", i, tcp.Options[i])
		}
	}
}

func buildIPv6TCPOpts(syn, ack, psh bool, window uint16, opts []layers.TCPOption, payload []byte) []byte {
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
		Options: opts,
	}
	_ = tcp.SetNetworkLayerForChecksum(ip)
	buf := gopacket.NewSerializeBuffer()
	ser := gopacket.SerializeOptions{ComputeChecksums: true, FixLengths: true}
	var err error
	if payload == nil {
		err = gopacket.SerializeLayers(buf, ser, ip, tcp)
	} else {
		err = gopacket.SerializeLayers(buf, ser, ip, tcp, gopacket.Payload(payload))
	}
	if err != nil {
		panic(err)
	}
	return buf.Bytes()
}
