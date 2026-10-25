package mysqlconnect

import (
	"crypto/tls"
	"database/sql"
	"fmt"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/go-sql-driver/mysql"
	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/sirupsen/logrus"
	"redi-multi/util/host"
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
	if host.ContainsControlChars(tlsMode) {
		return "", fmt.Errorf("mysql: REDI_DB_TLS contains invalid control characters")
	}
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

func applyTLSMode(cfg *mysql.Config, tlsMode string) error {
	tlsMode = strings.TrimSpace(tlsMode)
	if host.ContainsControlChars(tlsMode) {
		return fmt.Errorf("mysql: REDI_DB_TLS contains invalid control characters")
	}
	if tlsMode == "" {
		return nil
	}
	if tlsMode == "1" || strings.EqualFold(tlsMode, "true") {
		cfg.TLSConfig = "true"
		return nil
	}
	if strings.EqualFold(tlsMode, "skip-verify") {
		tlsRegisterOnce.Do(func() {
			_ = mysql.RegisterTLSConfig("redi-skip-verify", &tls.Config{InsecureSkipVerify: true})
		})
		cfg.TLSConfig = "redi-skip-verify"
		return nil
	}
	return fmt.Errorf("unsupported REDI_DB_TLS value %q (use 1, true, or skip-verify)", tlsMode)
}

func buildMySQLConfig(userName, password, ipAddress, port, dbName, charset string) (*mysql.Config, error) {
	for _, part := range []string{userName, password, ipAddress, port, dbName, charset} {
		if host.ContainsControlChars(part) {
			return nil, fmt.Errorf("mysql: REDI_DB_* env contains invalid control characters")
		}
	}
	cfg := &mysql.Config{
		User:   userName,
		Passwd: password,
		Net:    "tcp",
		Addr:   fmt.Sprintf("%s:%s", ipAddress, port),
		DBName: dbName,
		Params: map[string]string{
			"charset":   charset,
			"parseTime": "true",
		},
		Timeout:      5 * time.Second,
		ReadTimeout:  5 * time.Second,
		WriteTimeout: 5 * time.Second,
	}
	if err := applyTLSMode(cfg, os.Getenv("REDI_DB_TLS")); err != nil {
		return nil, err
	}
	return cfg, nil
}

func openPool() (*sqlx.DB, error) {
	userName := envOr("REDI_DB_USER", "redi_node")
	password := envOr("REDI_DB_PASSWORD", "")
	ipAddress := envOr("REDI_DB_HOST", "127.0.0.1")
	port := envOr("REDI_DB_PORT", "3306")
	dbName := envOr("REDI_DB_NAME", "redi_admin")
	charset := envOr("REDI_DB_CHARSET", "utf8mb4")

	cfg, err := buildMySQLConfig(userName, password, ipAddress, port, dbName, charset)
	if err != nil {
		return nil, err
	}
	connector, err := mysql.NewConnector(cfg)
	if err != nil {
		return nil, err
	}
	stdDB := sql.OpenDB(connector)
	conn := sqlx.NewDb(stdDB, "mysql")
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

// Reconnect 在查询失败时尝试重建连接池（先建好新池再替换，避免热路径长时间 db=nil）。
func Reconnect() error {
	conn, err := openPool()
	if err != nil {
		logrus.Errorf("[mysql] reconnect failed: %v", err)
		return err
	}
	mu.Lock()
	old := db
	db = conn
	mu.Unlock()
	if old != nil {
		_ = old.Close()
	}
	return nil
}

// ConnectMysql 兼容旧调用，等价于 DB()。
func ConnectMysql() *sqlx.DB {
	return DB()
}
