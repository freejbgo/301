<?php

return [
    'page' => [
        'title' => 'Lagran 设置',
        'description' => '全部节点共用。保存后在线节点会自己重新加载',
    ],
    'cards' => [
        'settings' => 'Lagran',
    ],
    'sections' => [
        'windows' => '窗口',
        'confusion' => '额外 ACK',
    ],
    'fields' => [
        'enabled' => '启用 lagran',
        'syn_ack' => '改写 SYN+ACK',
        'ack' => '改写纯 ACK',
        'window_80' => '80 端口窗口',
        'window_443' => '443 端口窗口',
        'split_number' => '纯 ACK 计次',
        'window_after_split' => '计满之后的窗口',
        'confusion_times' => '额外 ACK 条数',
        'window_scale' => 'Window Scale',
        'window_confusion_last' => '最后一条额外 ACK 的窗口',
    ],
    'help' => [
        'intro' => '保存后，在线节点大约 5 秒内取走这份设定，并在本进程里重新加载 lagran。不用登录每台机器，也不用把节点进程整个重启。80 和 443 的窗口分开设置。本机 REDI_LAGRAN_DISABLED=1 仍然强制关闭。队列号不在这里改。更早的节点程序不会读取这份设定，继续用本机 config.json。',
        'enabled' => '关闭后，节点会卸掉自己装上的 OUTPUT 规则。',
        'syn_ack' => '对应出站握手包。关闭后这类包不再改窗口。',
        'ack' => '对应不带数据的纯 ACK。关闭后不再按计次改窗口。',
        'window_80' => '源端口 80 的窗口，1–65535。SYN+ACK、额外 ACK，以及还没计满的纯 ACK 用它。',
        'window_443' => '源端口 443 的窗口，和 80 分开。',
        'split_number' => '同一条流上的纯 ACK 计到这个数之后，改用下面的窗口。默认 7。',
        'window_after_split' => '纯 ACK 计满之后的窗口。默认 28960。',
        'confusion_times' => '每个 SYN+ACK 额外发出的 ACK 条数。默认 0，表示不发。大于 0 才需要 cap_net_raw。',
        'window_scale' => '只写进额外 ACK，范围 0–14，默认 7。条数为 0 时用不到。',
        'window_confusion_last' => '最后一条额外 ACK 的窗口。默认 65535。条数为 0 时用不到。',
    ],
    'messages' => [
        'saved' => '已保存。在线节点会在下一次同步时重新加载 lagran。',
        'forbidden' => '只有超级管理员可以修改 Lagran 设置',
        'invalid' => '设定无效',
        'invalid_bool' => '开关的值无效',
        'missing_table' => '还没有 lagran 设定表，请先执行 php artisan migrate',
    ],
    'invalid' => [
        'window_scale' => 'Window Scale 须是 0 到 14 的整数',
        'confusion_times' => '额外 ACK 条数须是 0 到 64 的整数',
        'split_number' => '纯 ACK 计次须是 0 到 1000000 的整数',
        'window_after_split' => '计满之后的窗口须是 1 到 65535 的整数',
        'window_confusion_last' => '最后一条额外 ACK 的窗口须是 1 到 65535 的整数',
        'window_80' => '80 端口窗口须是 1 到 65535 的整数',
        'window_443' => '443 端口窗口须是 1 到 65535 的整数',
    ],
];
