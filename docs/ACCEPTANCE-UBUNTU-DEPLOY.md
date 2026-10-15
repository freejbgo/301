# Ubuntu 22.04 / 24.04 部署验收清单

与 [ACCEPTANCE-CENTOS7-DEPLOY.md](ACCEPTANCE-CENTOS7-DEPLOY.md) 同级，针对 `scripts/ubuntu/deploy-*.sh`。业务 F1–F8 仍用 [ACCEPTANCE-EDGE-POOL.md](ACCEPTANCE-EDGE-POOL.md)。

无 systemd 的 Cloud Agent 可用 `run-smoke-acceptance.sh` 做脚本级检查；`systemctl` / `ufw status` 相关项须在真实 LTS VPS 再验。

## 主控

- [ ] `lsb_release -ds` 为 **22.04** 或 **24.04**
- [ ] `sudo bash scripts/ubuntu/deploy-master.sh` 退出码 0
- [ ] `php -v` 为 8.1.x（22.04）或 8.3.x（24.04）
- [ ] `systemctl is-active nginx mariadb php*-fpm redi-queue` 均为 active
- [ ] `/etc/cron.d/redi-renew` 含 03:10 / 04:10
- [ ] `sudo ufw status` 含 22、80、443；3306 仅节点 IP（若已配 `NODE_IPS`）
- [ ] `SHOW PROCEDURE STATUS` 存在 `redi_node_bump_skip`
- [ ] `curl -sI http://127.0.0.1/admin` 为 200/302

## 节点

- [ ] `sudo bash scripts/ubuntu/deploy-node.sh` 退出码 0
- [ ] `systemctl is-active redi-multi`；`getcap` 含 `cap_net_admin`
- [ ] 从节点 `mysql -h 主控IP -u redi_node … CALL redi_node_bump_skip('__smoke__')` 成功
- [ ] 后台节点有心跳（Token 已配置）

签收后继续做 F1–F8。
