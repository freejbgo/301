package lagran

import (
	"fmt"
	"sync"

	"redi-multi/config"

	"github.com/coreos/go-iptables/iptables"
	"github.com/panjf2000/ants/v2"
	"github.com/sirupsen/logrus"
)

var (
	runtimeMu      sync.Mutex
	reloadRuntime  = reloadRules
	rulesInstalled bool
	installedSport string
	installedSyn   bool
	installedAck   bool
	startedQueues  = map[string]bool{}
	// 保住池的引用。队列读取会一直阻塞，进程退出前不 Release。
	startedPools = []*ants.PoolWithFunc{}
)

// SetReloadRuntimeForTest 替换重新装规则的动作。传 nil 恢复默认。
func SetReloadRuntimeForTest(fn func() error) {
	runtimeMu.Lock()
	defer runtimeMu.Unlock()
	if fn == nil {
		reloadRuntime = reloadRules
		return
	}
	reloadRuntime = fn
}

func reloadEntry() error {
	runtimeMu.Lock()
	fn := reloadRuntime
	runtimeMu.Unlock()
	return fn()
}

// ApplyFromMaster 写入主控设定，尽量写回 config.json，然后在本进程里重新装 lagran。
// 不重启 redi-multi。REDI_LAGRAN_DISABLED 仍强制关闭。
func ApplyFromMaster(p config.LagranPolicy) error {
	if err := config.ApplyLagranPolicy(p); err != nil {
		return err
	}
	if err := config.PersistLagranSection(); err != nil {
		logrus.Errorf("[lagran] policy is in memory but config.json was not updated: %v", err)
	}
	if err := reloadEntry(); err != nil {
		return err
	}
	logrus.Warnf("[lagran] master policy applied enabled=%v syn_ack=%v ack=%v window_80=%d window_443=%d after_split=%d split=%d confusion=%d scale=%d confusion_last=%d",
		p.Enabled, p.SynAck, p.Ack, p.Window["80"], p.Window["443"], p.WindowAfterSplit, p.SplitNumber, p.ConfusionTimes, p.WindowScale, p.WindowConfusionLast)
	return nil
}

func reloadRules() error {
	runtimeMu.Lock()
	defer runtimeMu.Unlock()
	if rulesInstalled {
		removeInstalledLocked()
		rulesInstalled = false
	}
	resetFlows()
	if !Enabled() {
		logrus.Warn("[lagran] disabled (REDI_LAGRAN_DISABLED or lagran.enabled=false)")
		return nil
	}
	sport := config.LagranSport()
	if !setIptable(sport) {
		return fmt.Errorf("iptables OUTPUT rules were not installed")
	}
	rulesInstalled = true
	installedSport = sport
	installedSyn = config.LagranBool("syn_ack", true)
	installedAck = config.LagranBool("ack", true)
	ensureWorkersLocked()
	return nil
}

// Shutdown 只卸掉本进程当前装上的规则。启动时若 lagran 是关的，这里什么都不做。
func Shutdown() {
	runtimeMu.Lock()
	defer runtimeMu.Unlock()
	if !rulesInstalled {
		return
	}
	removeInstalledLocked()
	rulesInstalled = false
}

func removeInstalledLocked() {
	removeFamily(installedSport, installedSyn, installedAck)
}

func removeFamily(sport string, syn, ack bool) {
	ipt, err := iptables.New()
	if err != nil {
		logrus.Errorf("[lagran] failed to new iptables, err: %v\n", err)
	} else {
		deleteLagranRules(ipt, sport, "iptables", syn, ack)
	}
	ip6, err := iptables.New(iptables.IPFamily(iptables.ProtocolIPv6))
	if err != nil {
		logrus.Warnf("[lagran] ip6tables new error during cleanup: %v", err)
		return
	}
	deleteLagranRules(ip6, sport, "ip6tables", syn, ack)
}

func ensureWorkersLocked() {
	startFlowJanitor()
	specs := []queuePoolSpec{
		{label: "p1", from: 1000, to: 1128, boolKey: "syn_ack"},
		{label: "p2", from: 2000, to: 2128, boolKey: "ack"},
	}
	for _, spec := range specs {
		if !config.LagranBool(spec.boolKey, true) {
			continue
		}
		if startedQueues[spec.label] {
			continue
		}
		p, err := ants.NewPoolWithFunc(128, func(i interface{}) {
			packetHandle(i.(int))
		})
		if err != nil {
			logrus.Errorf("[lagran] %s pool: %v", spec.label, err)
			continue
		}
		startedPools = append(startedPools, p)
		startedQueues[spec.label] = true
		logrus.Debugf("[lagran] Starting Task %s", spec.label)
		for q := spec.from; q < spec.to; q++ {
			_ = p.Invoke(q)
		}
	}
}
