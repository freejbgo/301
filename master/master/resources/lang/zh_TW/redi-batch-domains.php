<?php

return [
    'fields' => [
        'group_id'         => '分組',
        'status'           => '狀態',
        'default_skip_url' => '統一跳轉地址',
        'lines'            => '域名列表',
    ],
    'options' => [
        'status' => [1 => '啟用', 2 => '停用'],
    ],
    'help' => [
        'status'           => '批量「啟用」時，每行域名須已解析指向平台',
        'default_skip_url' => '方式一：填寫此項後，下面每行可只寫原始域名，全部跳到同一地址。',
        'lines'            => '最多 :max 條/次。點擊輸入框後灰色提示會消失，屬正常現象。',
    ],
    'placeholder' => "【填寫說明，點擊輸入後本提示消失】\n· 單次最多 :max 條（一行一條，空行不算；# 開頭為註釋）\n· 方式一：上面填「統一跳轉地址」，這裡每行只寫原始域名，如：example.com\n· 方式二：每行寫「原始域名,跳轉地址」，逗號/豎線/Tab 均可，如：foo.com,https://a.com\n· 原始域名不要帶 http://；本帳號已存在的域名會自動跳過\n· 本批共用上方所選分組與狀態\n\n示例：\nexample.com\nfoo.com,https://target.example.com\n# 下面再寫更多域名…",
    'messages' => [
        'group_invalid'     => '分組無效',
        'group_wrong_owner' => '分組無效或不屬於當前帳號',
        'nothing_imported'  => '未新增任何域名',
        'summary'           => '新增 :imported 條，跳過重複 :skipped 條。',
        'partial_errors'    => "部分行未寫入：\n:errors",
    ],
    'errors' => [
        'status_invalid'       => '狀態只能是啟用或停用',
        'group_invalid'        => '分組無效或不屬於本帳號',
        'no_lines'             => '請至少填寫一行域名',
        'too_many_lines'       => '單次最多 :max 條（當前 :count 條），請分批匯入。',
        'row_original_empty'   => '第 :row 行：原始域名不能為空或含路徑',
        'row_original_invalid' => '第 :row 行（:label）：原始域名格式無效',
        'row_skip_missing'     => '第 :row 行（:label）：缺少跳轉地址；請填統一跳轉或在本行寫「域名,地址」',
        'row_dns_required'     => '第 :row 行（:label）：啟用前須 CNAME 到 :edge 或 A 到節點公網 IP',
        'row_write_failed'     => '第 :row 行（:label）：寫入失敗',
    ],
];
