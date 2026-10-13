package log

import (
	"github.com/sirupsen/logrus"
	"redi-multi/config"
)

func Init() {
	if config.Debug {
		logrus.SetLevel(logrus.DebugLevel)
	} else {
		logrus.SetLevel(logrus.WarnLevel)
	}
	logrus.SetFormatter(&logrus.TextFormatter{})
}
