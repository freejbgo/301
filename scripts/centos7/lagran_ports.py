#!/usr/bin/env python3
"""与 node/redi-multi/config MergeListenerPorts + LagranSport 对齐，供 bash 运维脚本使用。

tls_addr 与 resolveTlsAddrFromJSON 一致：缺这个键时默认 0.0.0.0:443；
JSON null 或空字符串表示关闭 HTTPS，不并入 443。
环境变量 REDI_HTTP_LISTEN / REDI_TLS_LISTEN 覆盖 config.json（与 Go applyEnvOverrides 一致）。
"""
from __future__ import annotations

import json
import os
import sys


def port_from_listen(addr: str) -> str:
    addr = (addr or "").strip()
    if not addr:
        return ""
    if addr.startswith("::"):
        rest = addr[2:]
        if rest and ":" not in rest:
            return rest
    if addr.startswith(":"):
        return addr[1:]
    if ":" in addr:
        host, port = addr.rsplit(":", 1)
        if port:
            return port
        return port_from_listen(host)
    return addr


def resolve_tls_addr(data: dict) -> str:
    """缺 tls_addr 时与 Go 相同，默认 0.0.0.0:443。null 或空串关闭 HTTPS。"""
    if "tls_addr" not in data:
        return "0.0.0.0:443"
    value = data["tls_addr"]
    if value is None:
        return ""
    if not isinstance(value, str):
        raise SystemExit("tls_addr 必须是字符串、null 或缺省")
    return value


def merge_listener_ports(lagran_port, http_addr: str, tls_addr: str) -> str:
    ports: set[str] = set()
    if isinstance(lagran_port, int):
        raw = str(lagran_port)
    else:
        raw = lagran_port if isinstance(lagran_port, str) else ""
    if raw.strip():
        for p in raw.split(","):
            p = p.strip()
            if p:
                ports.add(p)
    for listen in (http_addr, tls_addr):
        p = port_from_listen(listen or "")
        if p:
            ports.add(p)
    if not ports:
        return "80"

    def sort_key(p: str) -> tuple:
        return (0, int(p)) if p.isdigit() else (1, p)

    return ",".join(sorted(ports, key=sort_key))


def lagran_bool(lag: dict, key: str, default: bool = True) -> bool:
    v = lag.get(key, default)
    return v not in (False, 0, "0", "false")


def apply_listen_env_overrides(http_addr: str, tls_addr: str) -> tuple[str, str]:
    """与 config.applyEnvOverrides 一致：REDI_HTTP_LISTEN / REDI_TLS_LISTEN 覆盖 config.json。"""
    if v := os.environ.get("REDI_HTTP_LISTEN", "").strip():
        http_addr = v
    if "REDI_TLS_LISTEN" in os.environ:
        tls_addr = os.environ.get("REDI_TLS_LISTEN", "").strip()
    return http_addr, tls_addr


def main() -> None:
    path = sys.argv[1]
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    lag = data.get("lagran") or {}
    lag_port = lag.get("port", "")
    http_addr = data.get("addr", "") or ""
    tls_addr = resolve_tls_addr(data)
    http_addr, tls_addr = apply_listen_env_overrides(http_addr, tls_addr)
    sport = merge_listener_ports(lag_port, http_addr, tls_addr)
    flags = (
        "1" if lagran_bool(lag, "syn_ack") else "0",
        "1" if lagran_bool(lag, "ack") else "0",
        "1" if lagran_bool(lag, "psh_ack") else "0",
        "1" if lagran_bool(lag, "fin_ack") else "0",
    )
    print(sport, *flags)


if __name__ == "__main__":
    main()
