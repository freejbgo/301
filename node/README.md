# Go 的安装与 301 跳转程序部署

出处：http://www.topgoer.com/开发环境/go的安装.html

## 1.1 下载地址

- Go 官网：https://golang.org/dl/
- Go 语言资源站：https://studygolang.com/dl

## 1.2 Linux 下安装

1. SSH 远程登录 Linux 服务器  
2. 安装 mercurial：`yum install mercurial -y`  
3. 安装 git：`yum install git -y`  
4. 安装 gcc：`yum install gcc -y`  
5. 下载 Go 压缩包（可选最新版本）：

```bash
cd /usr/local/
wget https://go.dev/dl/go1.20.2.linux-amd64.tar.gz
```

注意：若无法访问外网，可到 Go 语言资源站下载后通过 FTP 上传到该目录。

6. 解压：

```bash
tar -zxvf go1.20.2.linux-amd64.tar.gz
```

7. 建立 Go 工作空间（GOPATH）。在 `/home` 下建 `go` 目录，再建三个子目录 `src`、`pkg`、`bin`：

```bash
cd /home/
mkdir go
cd go/
mkdir bin src pkg
```

8. 配置环境变量，编辑 `/etc/profile`，加入：

```bash
export GOROOT=/usr/local/go
export PATH=$GOROOT/bin:$PATH
export GOPATH=/home/go
```

保存后执行 `source /etc/profile`。

9. 验证：`go version`（应显示如 `go version go1.20.2 linux/amd64`）

10. 查看环境：`go env`

## 1.3 清空防火墙规则

```bash
systemctl status firewalld.service
systemctl stop firewalld.service
iptables -nvL
iptables -F   # 清空所有防火墙规则
iptables -X   # 删除用户自定义的空链
iptables -Z   # 清空计数
```

## 1.4 配置 301 跳转服务器

将源文件 `redi-multi` 复制到 `/home/go/src`：

```bash
cp redi-multi.zip /home/go/src
cd /home/go/src
unzip redi-multi.zip
cd redi-multi
```

## 1.5 修改数据库 IP

修改源文件中的数据库 IP 为**后台（主控）IP**。

## 1.6 修改 config.json

将 `config.json` 中的 IP 改为**跳转服务器外网 IP**。

## 1.7 启动 301 跳转程序

```bash
cd /home/go/src/redi-multi
nohup go run main.go &
```
