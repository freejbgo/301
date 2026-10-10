package mysqlconnect

import (
	"fmt"
	"os"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func ConnectMysql() *sqlx.DB {
	userName := envOr("REDI_DB_USER", "redi_admin")
	password := envOr("REDI_DB_PASSWORD", "")
	ipAddress := envOr("REDI_DB_HOST", "127.0.0.1")
	port := envOr("REDI_DB_PORT", "3306")
	dbName := envOr("REDI_DB_NAME", "redi_admin")
	charset := envOr("REDI_DB_CHARSET", "utf8")

	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?charset=%s", userName, password, ipAddress, port, dbName, charset)
	Db, err := sqlx.Open("mysql", dsn)
	if err != nil {
		fmt.Printf("mysql connect failed, detail is [%v]", err.Error())
	}

	return Db
}
