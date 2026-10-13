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
	mu sync.Mutex
	db *sqlx.DB
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func openPool() (*sqlx.DB, error) {
	userName := envOr("REDI_DB_USER", "redi_admin")
	password := envOr("REDI_DB_PASSWORD", "")
	ipAddress := envOr("REDI_DB_HOST", "127.0.0.1")
	port := envOr("REDI_DB_PORT", "3306")
	dbName := envOr("REDI_DB_NAME", "redi_admin")
	charset := envOr("REDI_DB_CHARSET", "utf8mb4")

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=%s&parseTime=true", userName, password, ipAddress, port, dbName, charset)
	conn, openErr := sqlx.Open("mysql", dsn)
	if openErr != nil {
		return nil, openErr
	}
	conn.SetMaxOpenConns(20)
	conn.SetMaxIdleConns(5)
	conn.SetConnMaxLifetime(5 * time.Minute)
	if pingErr := conn.Ping(); pingErr != nil {
		_ = conn.Close()
		return nil, pingErr
	}
	return conn, nil
}

// Init 建立连接池并在启动时探测一次。失败时返回错误，进程仍可启动（仅跳转页依赖库）。
func Init() error {
	mu.Lock()
	defer mu.Unlock()
	if db != nil {
		if err := db.Ping(); err == nil {
			return nil
		}
		_ = db.Close()
		db = nil
	}
	conn, err := openPool()
	if err != nil {
		return err
	}
	db = conn
	return nil
}

// DB 返回共享连接池；连接失效时会尝试重连一次。
func DB() *sqlx.DB {
	mu.Lock()
	defer mu.Unlock()
	if db != nil {
		pingErr := db.Ping()
		if pingErr == nil {
			return db
		}
		logrus.Warnf("[mysql] ping failed, reconnecting: %v", pingErr)
		_ = db.Close()
		db = nil
	}
	conn, err := openPool()
	if err != nil {
		logrus.Errorf("[mysql] %v", err)
		return nil
	}
	db = conn
	return db
}

// ConnectMysql 兼容旧调用，等价于 DB()。
func ConnectMysql() *sqlx.DB {
	return DB()
}
