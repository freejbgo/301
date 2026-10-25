package masteragent

import (
	"strings"
	"time"
)

const (
	basePollInterval = 5 * time.Second
	maxPollInterval  = 60 * time.Second
)

func isAgentUpgradeRequired(err error) bool {
	if err == nil {
		return false
	}
	msg := err.Error()
	return strings.Contains(msg, "agent upgrade required (426)") || strings.Contains(msg, "(426)")
}

func nextPollIntervalAfterError(current time.Duration, err error) time.Duration {
	if err == nil {
		return basePollInterval
	}
	if isAgentUpgradeRequired(err) {
		return maxPollInterval
	}
	next := current * 2
	if next < basePollInterval {
		next = basePollInterval
	}
	if next > maxPollInterval {
		return maxPollInterval
	}
	return next
}
