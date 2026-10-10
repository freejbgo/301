# 301 主控安装

完整安全与上线清单见仓库根目录 [SECURITY.md](../SECURITY.md)。

## 功能说明（维护）

### 批量添加域名

后台 **域名管理 → 批量添加域名**：在弹窗中选择分组，在文本框中每行填写一条记录。

- 仅写 **原始域名** 时，需填写上方的 **统一跳转地址**
- 或每行写 **`原始域名,跳转地址`**（也支持 Tab、竖线分隔）
- `#` 开头的行视为注释；重复域名（本账号下）自动跳过
- **单次最多 500 条**（有效行，不含空行与注释）

弹窗内输入框使用灰色占位说明，**点击开始输入后占位文字会自动消失**（浏览器默认行为）。

### 跳转次数（点击统计）

列表字段 **跳转次数** 对应表 `redi_admin_domain.skip_num`。由节点在访问且域名 **启用（status=1）** 时写库递增。节点配置见 `node/README.md`。

### 分组删除

分组下仍有域名时不允许删除。列表中对已删分组显示「（分组不存在）」。

### 系统外观（全平台一套）

超级管理员登录后：**站点配置 → 系统外观**，可修改 Logo、系统名称、浏览器标题、登录框标题、Favicon。设置保存在 `admin_settings` 表；上传图片需已执行 `php artisan storage:link`。

后台底部版权栏已默认隐藏。

---

1. 宝塔安装 Apache、MySQL 5.6、PHP 7.4

2. 网站设置：运行目录设置为 `public`；重定向：`login301` → `301login/admin`

3. MySQL 访问权限：设置为所有人或指定 IP 访问

4. MySQL 日志若出现 `[Warning] IP address 'xxxx' could not be resolved: Name or service not known`，那是因为 MySQL 默认会反向解析 DNS，对于访问者 MySQL 不会判断是 hosts 还是 IP 都会进行 DNS 反向解析，频繁地查询数据库和权限检查，这大大增加了数据库的压力，导致数据库连接缓慢，严重的时候甚至死机，出现「连接数据库时出错」等字样。

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

`vi /home/go/src/redi-multi/mysqlconnect/mysqlconnect.go`（或使用环境变量 `REDI_DB_HOST` 等，见 `node/README.md`）

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
