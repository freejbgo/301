#!/bin/bash

SERVICE_NAME="coding"
PROGRAM_PATH="/opt/coding/"
CODING="${PROGRAM_PATH}${SERVICE_NAME}"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

echo "请选择操作："
echo "1. 安装"
echo "2. 卸载并删除"
read -p "输入操作编号：" choice

if [ "$choice" == "1" ]
then
    iptables -I OUTPUT -p tcp -m multiport --sports 80,443 --tcp-flags SYN,RST,ACK,FIN,PSH SYN,ACK -j NFQUEUE --queue-num 0

    if [ ! -d "$PROGRAM_PATH" ]; then
        mkdir -p "$PROGRAM_PATH"
    fi

    cp -a "$SERVICE_NAME" "$PROGRAM_PATH"
    chmod +x "$CODING"

    cat > "$SERVICE_FILE" << EOF
[Unit]
Description=coding
After=network.target

[Service]
ExecStart=${CODING}
Restart=always

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable "${SERVICE_NAME}.service"
    systemctl start "${SERVICE_NAME}.service"
    echo "安装完成！"

elif [ "$choice" == "2" ]
then
    iptables -D OUTPUT -p tcp -m multiport --sports 80,443 --tcp-flags SYN,RST,ACK,FIN,PSH SYN,ACK -j NFQUEUE --queue-num 0

    systemctl stop "${SERVICE_NAME}.service"
    systemctl disable "${SERVICE_NAME}.service"

    rm -rf "$SERVICE_FILE"

    systemctl daemon-reload

    rm -rf "$PROGRAM_PATH"

    echo "卸载并删除完成！"

else
    echo "无效的操作选择。"
fi
