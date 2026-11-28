package main

import (
	"os"
	"os/signal"
	"redi-multi/api"
	"redi-multi/config"
	"redi-multi/http"
	"redi-multi/https"
	"redi-multi/lagran"
	"redi-multi/log"
	"redi-multi/masteragent"
	"redi-multi/mysqlconnect"
	"syscall"
	"time"

	"github.com/gookit/color"
	"github.com/sirupsen/logrus"
)

func init() {
	config.Init()
	log.Init()
}

func main() {
	if err := mysqlconnect.Init(); err != nil {
		logrus.Warnf("[mysql] init failed (redirect /api.js may not work until DB is reachable): %v", err)
	}
	lagran.Run()
	masteragent.Run()
	go api.Start()
	go http.Start()
	go https.Start(config.TlsAddr)
	color.Greenf("[%v] App is running...\n", time.Now().Format("2006-06-02 15:04:05"))
	sigs := make(chan os.Signal, 1)
	done := make(chan bool, 1)
	signal.Notify(sigs, os.Interrupt, syscall.SIGTERM)
	go func() {
		<-sigs
		lagran.Shutdown()
		logrus.Debug("Unset iptables...")
		done <- true
		os.Exit(0)
	}()
	<-done
}
