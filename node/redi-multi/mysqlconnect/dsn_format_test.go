package mysqlconnect

import (
	"testing"

	"github.com/go-sql-driver/mysql"
)

func TestMySQLConfigAcceptsSpecialPassword(t *testing.T) {
	cfg, err := buildMySQLConfig("redi_node", "p@ss:word", "127.0.0.1", "3306", "redi_admin", "utf8mb4")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := mysql.NewConnector(cfg); err != nil {
		t.Fatalf("connector: %v", err)
	}
	if !cfg.AllowNativePasswords {
		t.Fatal("AllowNativePasswords must stay on for MariaDB mysql_native_password accounts")
	}
	if !cfg.ParseTime {
		t.Fatal("ParseTime must be set on the client config")
	}
	if _, ok := cfg.Params["parseTime"]; ok {
		t.Fatal("parseTime must not be sent to MariaDB as a system variable")
	}
	if cfg.Params["charset"] != "utf8mb4" {
		t.Fatalf("charset param = %q", cfg.Params["charset"])
	}
}
