<?php

return [
    'fields' => [
        'group_id'         => '分组',
        'status'           => '状态',
        'default_skip_url' => '统一跳转地址',
        'lines'            => '域名列表',
    ],
    'options' => [
        'status' => [1 => '启用', 2 => '停用'],
    ],
    'help' => [
        'status'           => '批量「启用」时，每行域名须已解析指向平台',
        'default_skip_url' => '方式一：填写此项后，下面每行可只写原始域名，全部跳到同一地址。',
        'lines'            => '最多 :max 条/次。点击输入框后灰色提示会消失，属正常现象。',
    ],
    'placeholder' => "【填写说明，点击输入后本提示消失】\n· 单次最多 :max 条（一行一条，空行不算；# 开头为注释）\n· 方式一：上面填「统一跳转地址」，这里每行只写原始域名，如：example.com\n· 方式二：每行写「原始域名,跳转地址」，逗号/竖线/Tab 均可，如：foo.com,https://a.com\n· 原始域名不要带 http://；本账号已存在的域名会自动跳过\n· 本批共用上方所选分组与状态\n\n示例：\nexample.com\nfoo.com,https://target.example.com\n# 下面再写更多域名…",
    'messages' => [
        'group_invalid'     => '分组无效',
        'group_wrong_owner' => '分组无效或不属于当前账号',
        'nothing_imported'  => '未新增任何域名',
        'summary'           => '新增 :imported 条，跳过重复 :skipped 条。',
        'partial_errors'    => "部分行未写入：\n:errors",
    ],
];
