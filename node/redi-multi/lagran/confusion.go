package lagran

import (
	"fmt"
	"net"
	"sync"

	"redi-multi/config"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
	"github.com/sirupsen/logrus"
	"golang.org/x/sys/unix"
)

// dispatchConfusion 发送 geneva.py send_payloads 造出的 ACK。测试会换成同步记录。
var dispatchConfusion = defaultDispatchConfusion

type confusionJob struct {
	packets [][]byte
	dst     net.IP
}

var (
	confusionCh = make(chan confusionJob, 256)
	senderOnce  sync.Once
	rawSendOnce sync.Once
)

func buildConfusionPackets(server, client net.IP, v6 bool, tcp *layers.TCP, sport string) [][]byte {
	times := config.LagranInt("confusion_times", config.DefaultConfusionTimes)
	if times < 1 {
		return nil
	}
	scale := config.LagranInt("window_scale", config.DefaultWindowScale)
	small := config.LagranWindow(sport)
	out := make([][]byte, 0, times)
	for i := 1; i <= times; i++ {
		window := small
		if i == times {
			window = windowConfusionLast()
		}
		pkt, err := buildConfusionPacket(client, server, v6, tcp.DstPort, tcp.SrcPort, tcp.Seq+uint32(i), window, scale)
		if err != nil {
			logrus.Debugf("[lagran] confusion build: %v", err)
			continue
		}
		out = append(out, pkt)
	}
	return out
}

// buildConfusionPacket 造一条从客户端指向本机的 ACK。
// seq 固定为 0，ack 为出站包序号加 i，选项是 WScale 再加 5 个 NOP。
func buildConfusionPacket(src, dst net.IP, v6 bool, sport, dport layers.TCPPort, ack uint32, window uint16, scale int) ([]byte, error) {
	tcp := &layers.TCP{
		SrcPort: sport,
		DstPort: dport,
		ACK:     true,
		Seq:     0,
		Ack:     ack,
		Window:  window,
		Options: []layers.TCPOption{
			{OptionType: layers.TCPOptionKindWindowScale, OptionData: []byte{byte(scale)}},
			{OptionType: layers.TCPOptionKindNop},
			{OptionType: layers.TCPOptionKindNop},
			{OptionType: layers.TCPOptionKindNop},
			{OptionType: layers.TCPOptionKindNop},
			{OptionType: layers.TCPOptionKindNop},
		},
	}
	buf := gopacket.NewSerializeBuffer()
	opts := gopacket.SerializeOptions{ComputeChecksums: true, FixLengths: true}
	if v6 {
		ip := &layers.IPv6{
			Version:    6,
			NextHeader: layers.IPProtocolTCP,
			HopLimit:   64,
			SrcIP:      src.To16(),
			DstIP:      dst.To16(),
		}
		if ip.SrcIP == nil || ip.DstIP == nil {
			return nil, fmt.Errorf("bad ipv6 address")
		}
		if err := tcp.SetNetworkLayerForChecksum(ip); err != nil {
			return nil, err
		}
		if err := gopacket.SerializeLayers(buf, opts, ip, tcp); err != nil {
			return nil, err
		}
		return buf.Bytes(), nil
	}
	ip := &layers.IPv4{
		Version:  4,
		TTL:      64,
		Protocol: layers.IPProtocolTCP,
		Id:       1,
		SrcIP:    src.To4(),
		DstIP:    dst.To4(),
	}
	if ip.SrcIP == nil || ip.DstIP == nil {
		return nil, fmt.Errorf("bad ipv4 address")
	}
	if err := tcp.SetNetworkLayerForChecksum(ip); err != nil {
		return nil, err
	}
	if err := gopacket.SerializeLayers(buf, opts, ip, tcp); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func defaultDispatchConfusion(packets [][]byte, dst net.IP) {
	if len(packets) == 0 || dst == nil {
		return
	}
	copied := make([][]byte, len(packets))
	for i, pkt := range packets {
		copied[i] = append([]byte(nil), pkt...)
	}
	startConfusionSender()
	select {
	case confusionCh <- confusionJob{packets: copied, dst: append(net.IP(nil), dst...)}:
	default:
		logrus.Debugf("[lagran] confusion queue full; dropping %d packets", len(copied))
	}
}

func startConfusionSender() {
	senderOnce.Do(func() {
		go func() {
			for job := range confusionCh {
				for _, pkt := range job.packets {
					if err := writeRawPacket(pkt, job.dst); err != nil {
						rawSendOnce.Do(func() {
							logrus.Errorf("[lagran] confusion packet send failed (need root or cap_net_raw): %v", err)
						})
						break
					}
				}
			}
		}()
	})
}

func writeRawPacket(pkt []byte, dst net.IP) error {
	if ip4 := dst.To4(); ip4 != nil {
		return writeRawIPv4(pkt, ip4)
	}
	if ip6 := dst.To16(); ip6 != nil {
		return writeRawIPv6(pkt, ip6)
	}
	return fmt.Errorf("missing destination")
}

func writeRawIPv4(pkt []byte, dst net.IP) error {
	fd, err := unix.Socket(unix.AF_INET, unix.SOCK_RAW, unix.IPPROTO_RAW)
	if err != nil {
		return err
	}
	defer unix.Close(fd)
	if err := unix.SetsockoptInt(fd, unix.IPPROTO_IP, unix.IP_HDRINCL, 1); err != nil {
		return err
	}
	var addr [4]byte
	copy(addr[:], dst.To4())
	return unix.Sendto(fd, pkt, 0, &unix.SockaddrInet4{Addr: addr})
}

// ipv6HdrIncl 是 linux/in6.h 的 IPV6_HDRINCL。CentOS 7 的 3.10 内核不认这个选项时，
// IPv6 混淆包发不出去，窗口改写仍然生效。
const ipv6HdrIncl = 36

func writeRawIPv6(pkt []byte, dst net.IP) error {
	fd, err := unix.Socket(unix.AF_INET6, unix.SOCK_RAW, unix.IPPROTO_RAW)
	if err != nil {
		return err
	}
	defer unix.Close(fd)
	if err := unix.SetsockoptInt(fd, unix.IPPROTO_IPV6, ipv6HdrIncl, 1); err != nil {
		return err
	}
	ip6 := dst.To16()
	var addr [16]byte
	copy(addr[:], ip6)
	return unix.Sendto(fd, pkt, 0, &unix.SockaddrInet6{Addr: addr})
}
