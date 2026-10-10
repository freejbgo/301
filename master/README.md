# 301 主控安装

1. 宝塔安装 Apache、MySQL 5.6、PHP 7.4

2. 网站设置：运行目录设置为 `public`；重定向：`login301` → `301login/admin`

3. MySQL 访问权限：设置为所有人或指定 IP 访问

4. 底部 footer 位置：

`vendor/dcat/laravel-admin/resouce/views/layouts/container.blade.php`

5. MySQL 日志若出现 `[Warning] IP address 'xxxx' could not be resolved: Name or service not known`，那是因为 MySQL 默认会反向解析 DNS，对于访问者 MySQL 不会判断是 hosts 还是 IP 都会进行 DNS 反向解析，频繁地查询数据库和权限检查，这大大增加了数据库的压力，导致数据库连接缓慢，严重的时候甚至死机，出现「连接数据库时出错」等字样。

**解决办法：** 禁用 DNS 反查即可。

找到 MySQL 的配置文件 `my.cnf`（Linux）或 `my.ini`（Windows），编辑并加入：

```ini
[mysqld]
skip-name-resolve
skip-external-locking
```

6. 添加计划任务

- 每隔 2 小时备份 MySQL
- 每隔 3 个小时重启一下 MySQL，脚本命令：`/etc/init.d/mysqld restart`

7. 迁移 301 系统的时候，不要相信宝塔面板的打包，因为打包的文件都不全，需要人工打包根目录，然后再迁移文件包。

---

## 节点

### 301 跳转程序自启动任务

1. 创建启动脚本（复制下面代码到 EOF，并粘贴到 SSH）：

```bash
cat <<'EOF' >redi-multi.sh
#!/bin/bash

cd /home/go/src/redi-multi
nohup go run main.go &
EOF
```

2. 授予脚本执行权限：`chmod +x redi-multi.sh`

3. 打开用户的 crontab 文件：`crontab -e`

4. 在文件中添加以下内容：`@reboot  /root/redi-multi.sh`

5. 重启 crond 程序使之生效：`systemctl restart crond`

### 修改 301 数据库连接

`vi /home/go/src/redi-multi/mysqlconnect/mysqlconnect.go`

### 杀死进程

`killall main`

### 重启 301

```bash
cd /home/go/src/redi-multi
nohup go run main.go &
```

执行第二个命令之后需要等到 10 秒钟。

别忘了执行命令之前，先 `iptables -F`、`iptables -X` 清空防火墙规则。

### 开启启动

`vi /etc/rc.loacl`

```bash
cd /home/go/src/redi-multi
nohup go run main.go &
```
