package main

import (
	"os"
	"os/signal"
	"redi-multi/api"
	"redi-multi/config"
	"redi-multi/http"
	"redi-multi/lagran"
	"redi-multi/log"
	"time"

	"github.com/gookit/color"
	"github.com/sirupsen/logrus"
)

func init() {
	config.Init()
	log.Init()
}

func main() {
	lagran.Run()
	go api.Start()
	go http.Start()
	color.Greenf("[%v] App is running...\n", time.Now().Format("2006-06-02 15:04:05"))
	sigs := make(chan os.Signal, 1)
	done := make(chan bool, 1)
	signal.Notify(sigs, os.Interrupt)
	go func() {
		<-sigs
		lagran.UnsetIptable(config.Lagran["port"].(string))
		logrus.Debug("Unset iptables...")
		done <- true
	}()
	<-done
	color.Redf("[%v] App terminated...\n", time.Now().Format("2006-06-02 15:04:05"))
}
