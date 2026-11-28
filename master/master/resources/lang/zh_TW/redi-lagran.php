<?php

return [
    'page' => [
        'title' => 'Lagran 設定',
        'description' => '全部節點共用。儲存後在線節點會自己重新載入',
    ],
    'cards' => [
        'settings' => 'Lagran',
    ],
    'sections' => [
        'windows' => '視窗',
        'confusion' => '額外 ACK',
    ],
    'fields' => [
        'enabled' => '啟用 lagran',
        'syn_ack' => '改寫 SYN+ACK',
        'ack' => '改寫純 ACK',
        'window_80' => '80 埠視窗',
        'window_443' => '443 埠視窗',
        'split_number' => '純 ACK 計次',
        'window_after_split' => '計滿之後的視窗',
        'confusion_times' => '額外 ACK 條數',
        'window_scale' => 'Window Scale',
        'window_confusion_last' => '最後一條額外 ACK 的視窗',
    ],
    'help' => [
        'intro' => '儲存後，在線節點大約 5 秒內取走這份設定，並在本進程裡重新載入 lagran。不用登入每台機器，也不用把節點進程整個重啟。80 和 443 的視窗分開設定。本機 REDI_LAGRAN_DISABLED=1 仍然強制關閉。佇列號不在這裡改。更早的節點程式不會讀取這份設定，繼續用本機 config.json。',
        'enabled' => '關閉後，節點會卸掉自己裝上的 OUTPUT 規則。',
        'syn_ack' => '對應出站握手包。關閉後這類包不再改視窗。',
        'ack' => '對應不帶資料的純 ACK。關閉後不再按計次改視窗。',
        'window_80' => '來源埠 80 的視窗，1–65535。SYN+ACK、額外 ACK，以及還沒計滿的純 ACK 用它。',
        'window_443' => '來源埠 443 的視窗，和 80 分開。',
        'split_number' => '同一條流上的純 ACK 計到這個數之後，改用下面的視窗。預設 7。',
        'window_after_split' => '純 ACK 計滿之後的視窗。預設 28960。',
        'confusion_times' => '每個 SYN+ACK 額外發出的 ACK 條數。預設 0，表示不發。大於 0 才需要 cap_net_raw。',
        'window_scale' => '只寫進額外 ACK，範圍 0–14，預設 7。條數為 0 時用不到。',
        'window_confusion_last' => '最後一條額外 ACK 的視窗。預設 65535。條數為 0 時用不到。',
    ],
    'messages' => [
        'saved' => '已儲存。在線節點會在下一次同步時重新載入 lagran。',
        'forbidden' => '只有超級管理員可以修改 Lagran 設定',
        'invalid' => '設定無效',
        'invalid_bool' => '開關的值無效',
        'missing_table' => '還沒有 lagran 設定表，請先執行 php artisan migrate',
    ],
    'invalid' => [
        'window_scale' => 'Window Scale 須是 0 到 14 的整數',
        'confusion_times' => '額外 ACK 條數須是 0 到 64 的整數',
        'split_number' => '純 ACK 計次須是 0 到 1000000 的整數',
        'window_after_split' => '計滿之後的視窗須是 1 到 65535 的整數',
        'window_confusion_last' => '最後一條額外 ACK 的視窗須是 1 到 65535 的整數',
        'window_80' => '80 埠視窗須是 1 到 65535 的整數',
        'window_443' => '443 埠視窗須是 1 到 65535 的整數',
    ],
];
