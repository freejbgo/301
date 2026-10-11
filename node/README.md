# Go 的安装与 301 跳转程序部署

出处：http://www.topgoer.com/开发环境/go的安装.html

## 1.1. 下载地址

Go官网下载地址：https://golang.org/dl/

Go语言资源站：https://studygolang.com/dl

## 1.2. Linux下安装

1. SSH远程登录你的linux服务器  
2. 安装 mercurial包：

```bash
yum install mercurial -y
```

3. 安装git包：

```bash
yum install git -y
```

4. 安装gcc：

```bash
yum install gcc -y
```

5. 下载Go的压缩包:(可选择最新的Go版本)

```bash
cd /usr/local/
wget https://go.dev/dl/go1.20.2.linux-amd64.tar.gz
```

注意：如果不能翻墙，去go语言资源站 下载相应的包。然后通过ftp上传到此目录。

6. 下载完成 or ftp上传完成，用tar 命令来解压压缩包。

```bash
tar -zxvf go1.20.2.linux-amd64.tar.gz
```

7. 建立Go的工作空间（workspace，也就是GOPATH环境变量指向的目录）

GO代码必须在工作空间内。工作空间是一个目录，其中包含三个子目录：

- src ---- 里面每一个子目录，就是一个包。包内是Go的源码文件  
- pkg ---- 编译后生成的，包的目标文件  
- bin ---- 生成的可执行文件  

这里，我们在/home目录下, 建立一个名为go(可以不是go, 任意名字都可以)的文件夹， 然后再建立三个子文件夹(子文件夹名必须为src、pkg、bin)。

```text
[root@localhost local]# cd /home/
[root@localhost home]# mkdir go
[root@localhost home]# cd go/
[root@localhost go]# mkdir bin src pkg
```

8. 添加PATH环境变量and设置GOPATH环境变量

```bash
vi /etc/profile
```

加入下面这三行:

```bash
export GOROOT=/usr/local/go
export PATH=$GOROOT/bin:$PATH
export GOPATH=/home/go
```

保存后，执行以下命令，使环境变量立即生效:

```bash
source /etc/profile
```

至此，Go语言的环境已经安装完毕。

9. 验证一下是否安装成功，如果出现下面的信息说明安装成功了

```text
[root@localhost go]# go version
go version go1.20.2 linux/amd64
```

10. 查看Go语言的环境信息

```text
[root@localhost go]# go env
```

## 1.3. 清空防护墙的规则

```bash
systemctl status firewalld.service
systemctl stop firewalld.service
iptables -nvL
iptables -F   # 清空所有的防火墙规则
iptables -X   # 删除用户自定义的空链
iptables -Z   # 清空计数
```

## 1.4. 配置301跳转服务器

把源文件redi-multi复制到/home/go/src目录里面：

```bash
cp redi-multi.zip /home/go/src
cd /home/go/src
unzip redi-multi.zip
cd redi-multi
```

## 1.5. 修改源文件中的数据库IP地址为后台IP

改这个文件：`/home/go/src/redi-multi/mysqlconnect/mysqlconnect.go`

工程目录是 `redi-multi`（上一节解压到 `/home/go/src` 之后的目录）。目录树里数据库连接在子目录 `mysqlconnect` 下，文件名是 `mysqlconnect.go`。同目录里还可能有编辑器临时文件 `mysql.go.swp`，不用改。

把变量 `ipAddrees` 的值改成后台服务器的 IP（源文件里这个变量名就是 `ipAddrees`）。

```go
package mysqlconnect

import (
	"fmt"
	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
)

var (
	userName  string = "redi_admin"
	password  string = "WNKfdMyX4iLZ3XTH"
	ipAddrees string = "127.0.0.1"
	port      int    = 3306
	dbName    string = "redi_admin"
	charset   string = "utf8"
)

func ConnectMysql() *sqlx.DB {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%d)/%s?charset=%s", userName, password, ipAddrees, port, dbName, charset)
	Db, err := sqlx.Open("mysql", dsn)
	if err != nil {
		fmt.Printf("mysql connect failed, detail is [%v]", err.Error())
	}

	return Db
}
```

要改的就是上面这段变量，其中 `ipAddrees` 当前是 `127.0.0.1`：

```go
var (
	userName  string = "redi_admin"
	password  string = "WNKfdMyX4iLZ3XTH"
	ipAddrees string = "127.0.0.1"
	port      int    = 3306
	dbName    string = "redi_admin"
	charset   string = "utf8"
)
```

## 1.6. 修改源文件中config.json的IP地址为跳转服务器的外网IP

改这个文件：`/home/go/src/redi-multi/config.json`

在 `redi-multi` 目录下执行 `ll`，`config.json` 和 `main.go`、`mysqlconnect`、`Makefile` 在同一层。终端提示符是 `[root@192-168-0-115 redi-multi]#`，当前目录就是这个 `redi-multi`。

```text
[root@192-168-0-115 redi-multi]# ll
total 19112
drwxr-xr-x 2 root root      20 Apr  6 16:04 api
drwxr-xr-x 2 root root      24 Feb 18 15:31 bin
drwxr-xr-x 2 root root      23 Apr  4 10:26 config
-rw-r--r-- 1 root root     375 Apr  6 16:11 config.json
-rw-r--r-- 1 root root    1729 Feb 20 16:32 go.mod
-rw-r--r-- 1 root root   14494 Feb 20 16:32 go.sum
drwxr-xr-x 2 root root      21 Mar 29 23:56 http
drwxr-xr-x 2 root root      23 Apr  6 15:56 lagran
drwxr-xr-x 2 root root      20 Mar  2 18:06 log
-rwxr-xr-x 1 root root 9719186 Feb 18 15:31 main
-rw-r--r-- 1 root root     717 Apr  6 10:35 main.go
-rw-r--r-- 1 root root     183 Feb 18 15:31 Makefile
drwxr-xr-x 2 root root      50 Apr  6 10:34 mysqlconnect
-rw------- 1 root root     823 Apr  6 15:54 nohup.out
-rwxr-xr-x 1 root root 9810489 Feb 18 15:39 redi-multi
drwxr-xr-x 2 root root      25 Apr  4 10:25 redirect
drwxr-xr-x 3 root root      20 Feb 18 15:31 util
```

打开上面的 `config.json`，把 `server_ip` 改成这台跳转服务器的外网 IP。文件内容如下，其中 `server_ip` 当前是 `127.0.0.1`：

```json
{
  "debug": false,
  "addr": "0.0.0.0:80",
  "server_ip": "127.0.0.1",
  "default_redirect_url": "https://www.baidu.com",
  "lagran": {
    "port": "80",
    "syn_ack": true,
    "ack": true,
    "psh_ack": true,
    "fin_ack": true
  },
  "redirector": {
    "domain1.com": "http://www.1.com",
    "domain2.com": "http:/www.2.com",
    "127.0.0.1": "http://baidu.com"
  }
}
```

## 1.7. 启动301跳转程序

```bash
cd /home/go/src/redi-multi
nohup go run main.go &
```

---

## 节点程序包

源码目录 **`redi-multi/`** 与发布包 **`redi-multi.tar.gz`**（含源码、`config.json`、`env.example` 及 `bin/redi-multi` 编译产物）。

重新打包（含 Agent / 443）：

```bash
./scripts/build-node-tar.sh
```

发布包与源码同步：改完节点代码后请执行上述命令并提交 `node/redi-multi.tar.gz`（CI/部署默认解压该包）。

### 跳转次数（`skip_num`）

主控 **跳转次数** 由节点在 `GET /api.js?d=...` 时直连 MySQL；仅当域名 **启用（status=1）** 时跳转并执行 `skip_num=skip_num+1`。

### 数据库环境变量（上线必填）

| 变量 | 说明 |
|------|------|
| `REDI_DB_HOST` | MySQL 地址 |
| `REDI_DB_PORT` | 端口，默认 `3306` |
| `REDI_DB_USER` | 用户，默认 `redi_admin` |
| `REDI_DB_PASSWORD` | 密码（勿写入仓库） |
| `REDI_DB_NAME` | 库名，默认 `redi_admin` |

另请修改 `config.json` 中 `server_ip` 等为当前节点外网 IP。

### 主控 Agent（边缘池 / 证书）

| 变量 | 说明 |
|------|------|
| `REDI_MASTER_URL` | 主控 HTTPS 根地址 |
| `REDI_NODE_TOKEN` | 后台「节点管理」创建节点时一次性 Token |

### 80 / 443 与 lagran（防火墙 NFQUEUE）

| 配置项 | 含义 |
|--------|------|
| `addr` | HTTP 监听，如 `0.0.0.0:80` |
| `tls_addr` | HTTPS 监听，如 `0.0.0.0:443`；**`""` 或 JSON `null` 关闭 HTTPS**（不写该字段则默认 `0.0.0.0:443`）。可用环境变量 `REDI_TLS_LISTEN` 覆盖 |
| `addr` | 可用 `REDI_HTTP_LISTEN` 覆盖 |
| `debug` | 可省略，默认 `false` |
| `lagran.port` | 可为字符串 `"80,443"` 或数字 `80`（JSON 数字） |
| `lagran` | 可省略，程序使用与示例相同的默认开关；**不要整段删掉后留空对象**（已兼容缺失时自动默认） |
| `lagran.port` | **出站** TCP 源端口，供 iptables `--sport` / `multiport` 匹配；格式 **`80,443`**（逗号分隔、不要空格） |

程序启动时会用 `addr` + `tls_addr` **自动合并**进 `lagran.port`（仅合并非空监听端口）：若关闭 HTTPS，则 lagran 不会并入 443。

示例见 `redi-multi/config.json.example`。Agent 拉取 ACME 与 TLS 证书（443 SNI）。

### 已移除的冗余包（对比结论）

| 已删除文件 | 与 `redi-multi.tar.gz` 关系 |
|------------|------------------------------|
| `redi-multi_Yk7RxC.tar.zip` | `api.go`、编译产物与标准包**完全一致**；仅 `config.json` / `mysqlconnect` 里示例 IP 不同 |
| `redi-multi-mysql版.zip` | **不同**：`api.go` **不递增** `skip_num`，不适合需要统计的场景 |
