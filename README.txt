301方案

复制下方代码保存文件(从cat复制到EOF，复制后粘贴到终端回车，会自动将代码保存成geneva.py)
cat <<'EOF' >geneva.py
#!/usr/bin/env python3

import os
import signal
from scapy.all import *
from netfilterqueue import NetfilterQueue
import argparse

window_size = 0

def modify_window(pkt):
    try:
        ip = IP(pkt.get_payload())
        if ip.haslayer(TCP) and ip[TCP].flags == "SA":
            ip[TCP].window = window_size
            del ip[IP].chksum
            del ip[TCP].chksum
            pkt.set_payload(bytes(ip))
        elif ip.haslayer(TCP) and ip[TCP].flags == "FA":
            ip[TCP].window = window_size
            del ip[IP].chksum
            del ip[TCP].chksum
            pkt.set_payload(bytes(ip))
        elif ip.haslayer(TCP) and ip[TCP].flags == "PA":
            ip[TCP].window = window_size
            del ip[IP].chksum
            del ip[TCP].chksum
            pkt.set_payload(bytes(ip))
        elif ip.haslayer(TCP) and ip[TCP].flags == "A":
            ip[TCP].window = window_size
            del ip[IP].chksum
            del ip[TCP].chksum
            pkt.set_payload(bytes(ip))
    except:
        pass

    pkt.accept()

def parsearg():
    global window_size  
    parser = argparse.ArgumentParser(description='Description of your program')

    parser.add_argument('-q', '--queue', type=int, help='iptables Queue Num')
    parser.add_argument('-w', '--window_size', type=int, help='Tcp Window Size')

    args = parser.parse_args()

    if args.queue is None or args.window_size is None:
        exit(1)
    
    window_size = args.window_size  

    return args.queue

def main():
    queue_num = parsearg()
    nfqueue = NetfilterQueue()
    nfqueue.bind(queue_num, modify_window)

    try:
        print("Starting netfilter_queue process...")
        nfqueue.run()
    except KeyboardInterrupt:
        pass

if __name__ == "__main__":
    #sys.stdout = os.fdopen(sys.stdout.fileno(), 'w', 0)
    signal.signal(signal.SIGINT, lambda signal, frame: sys.exit(0))
    main()
EOF



CentOS 安装依赖(centos7测试已通过)
yum install -y python3 python3-devel gcc gcc-c++ git libnetfilter* libffi-devel
pip3 install --upgrade pip
pip3 install scapy netfilterqueue

Ubuntu安装依赖(Ubuntu 22测试已通过)
sudo apt-get install build-essential python3-dev libnetfilter-queue-dev libffi-dev libssl-dev iptables python3-pip -y
pip3 install scapy netfilterqueue

执行程序:
nohup python3 geneva.py -q 100 -w 0 &
iptables -I OUTPUT -p tcp --sport 80 -j NFQUEUE --queue-num 100

如何查看是否运行成功？
输入命令ps -ef|grep geneva出现下方内容为启动成功
root     123  1083  0 23:58 pts/0    00:00:00 python3 geneva.py -q 100 -w 0
root     1098 1083  0 23:58 pts/0    00:00:00 grep --color=auto geneva

下载js301：
wget https://github.com/freejbgo/301/releases/download/v1.5/js301.tar.gz

解压js301：
tar zxvf js301.tar.gz

编辑js301.config.json，自行添加映射关系。

取消ulimit限制：
ulimit -n 65535

执行js301：
nohup ./js301 js301.config.json &

如何查看是否运行成功？
输入命令ps -ef|grep js301出现下方内容为启动成功
root     123  1083  0 23:58 pts/0    00:00:00 js301 js301.config.json
root     1098 1083  0 23:58 pts/0    00:00:00 grep --color=auto js301

以后更新了js301.config.json后，在js301目录中执行命令刷新js301配置：
touch refresh

