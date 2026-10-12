package mysqlconnect

import (
	"fmt"
	"os"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/sirupsen/logrus"
)

var (
	once sync.Once
	db   *sqlx.DB
	err  error
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// Init 建立连接池并在启动时探测一次。失败时返回错误，进程仍可启动（仅跳转页依赖库）。
func Init() error {
	once.Do(func() {
		userName := envOr("REDI_DB_USER", "redi_admin")
		password := envOr("REDI_DB_PASSWORD", "")
		ipAddress := envOr("REDI_DB_HOST", "127.0.0.1")
		port := envOr("REDI_DB_PORT", "3306")
		dbName := envOr("REDI_DB_NAME", "redi_admin")
		charset := envOr("REDI_DB_CHARSET", "utf8mb4")

		dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=%s&parseTime=true", userName, password, ipAddress, port, dbName, charset)
		conn, openErr := sqlx.Open("mysql", dsn)
		if openErr != nil {
			err = openErr
			return
		}
		conn.SetMaxOpenConns(20)
		conn.SetMaxIdleConns(5)
		conn.SetConnMaxLifetime(5 * time.Minute)
		if pingErr := conn.Ping(); pingErr != nil {
			_ = conn.Close()
			err = pingErr
			return
		}
		db = conn
	})
	return err
}

// DB 返回共享连接池。未 Init 成功时返回 nil。
func DB() *sqlx.DB {
	if err := Init(); err != nil {
		logrus.Errorf("[mysql] %v", err)
		return nil
	}
	return db
}

// ConnectMysql 兼容旧调用，等价于 DB()。
func ConnectMysql() *sqlx.DB {
	return DB()
}
