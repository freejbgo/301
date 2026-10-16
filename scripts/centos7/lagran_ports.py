#!/usr/bin/env python3
"""与 node/redi-multi/config MergeListenerPorts + LagranSport 对齐，供 bash 运维脚本使用。"""
from __future__ import annotations

import json
import sys


def port_from_listen(addr: str) -> str:
    addr = (addr or "").strip()
    if not addr:
        return ""
    if addr.startswith(":"):
        return addr[1:]
    if ":" in addr:
        host, port = addr.rsplit(":", 1)
        if port:
            return port
        return port_from_listen(host)
    return addr


def merge_listener_ports(lagran_port, http_addr: str, tls_addr: str) -> str:
    ports: set[str] = set()
    raw = lagran_port
    if isinstance(raw, int):
        raw = str(raw)
    if isinstance(raw, str) and raw.strip():
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


def main() -> None:
    path = sys.argv[1]
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    lag = data.get("lagran") or {}
    lag_port = lag.get("port", "")
    sport = merge_listener_ports(lag_port, data.get("addr", ""), data.get("tls_addr", ""))
    flags = (
        "1" if lagran_bool(lag, "syn_ack") else "0",
        "1" if lagran_bool(lag, "ack") else "0",
        "1" if lagran_bool(lag, "psh_ack") else "0",
        "1" if lagran_bool(lag, "fin_ack") else "0",
    )
    print(sport, *flags)


if __name__ == "__main__":
    main()
