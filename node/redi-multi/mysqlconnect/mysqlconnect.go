package mysqlconnect

import (
	"crypto/tls"
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/go-sql-driver/mysql"
	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/sirupsen/logrus"
)

var (
	mu              sync.Mutex
	db              *sqlx.DB
	tlsRegisterOnce sync.Once
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func appendTlsToDSN(dsn string, tlsMode string) (string, error) {
	tlsMode = strings.TrimSpace(tlsMode)
	if tlsMode == "" {
		return dsn, nil
	}
	if tlsMode == "1" || strings.EqualFold(tlsMode, "true") {
		return dsn + "&tls=true", nil
	}
	if strings.EqualFold(tlsMode, "skip-verify") {
		tlsRegisterOnce.Do(func() {
			_ = mysql.RegisterTLSConfig("redi-skip-verify", &tls.Config{InsecureSkipVerify: true})
		})
		return dsn + "&tls=redi-skip-verify", nil
	}

	return "", fmt.Errorf("unsupported REDI_DB_TLS value %q (use 1, true, or skip-verify)", tlsMode)
}

func openPool() (*sqlx.DB, error) {
	userName := envOr("REDI_DB_USER", "redi_node")
	password := envOr("REDI_DB_PASSWORD", "")
	ipAddress := envOr("REDI_DB_HOST", "127.0.0.1")
	port := envOr("REDI_DB_PORT", "3306")
	dbName := envOr("REDI_DB_NAME", "redi_admin")
	charset := envOr("REDI_DB_CHARSET", "utf8mb4")

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=%s&parseTime=true", userName, password, ipAddress, port, dbName, charset)
	dsn, err := appendTlsToDSN(dsn, os.Getenv("REDI_DB_TLS"))
	if err != nil {
		return nil, err
	}
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

// DB 返回共享连接池（热路径不 Ping；失败时请调 Reconnect）。
func DB() *sqlx.DB {
	mu.Lock()
	defer mu.Unlock()
	return db
}

// Reconnect 在查询失败时尝试重建连接池。
func Reconnect() error {
	mu.Lock()
	defer mu.Unlock()
	if db != nil {
		_ = db.Close()
		db = nil
	}
	conn, err := openPool()
	if err != nil {
		logrus.Errorf("[mysql] reconnect failed: %v", err)
		return err
	}
	db = conn
	return nil
}

// ConnectMysql 兼容旧调用，等价于 DB()。
func ConnectMysql() *sqlx.DB {
	return DB()
}
