<?php

return [
    'issues' => [
        'node_stale' => '節點 #:id :name (:ip) 超過 15 分鐘無心跳',
        'cert_deploy_pending' => '仍有 :count 條憑證部署待節點拉取/確認（pending）',
        'cert_deploy_failed' => '有 :count 條憑證在節點上安裝失敗（failed）',
        'cert_expired' => '憑證已過期：:host（:date，狀態 :status）',
        'cert_expiring' => '憑證將在 :days 天內到期：:host（:date，狀態 :status）',
        'app_debug_enabled' => 'APP_DEBUG=true：生產環境請關閉調試並執行 php artisan config:cache',
        'acme_email_missing' => '未配置 ACME 聯繫郵箱（後台 DNS 與節點池），無法簽發憑證',
        'acme_staging_enabled' => '生產環境仍開啟 ACME Staging；正式憑證請關閉「ACME 測試環境」',
        'edge_zone_missing' => '未配置平台 edge 主域名（edge_zone），租戶 CNAME 與憑證流程不完整',
        'dns_driver_invalid' => 'DNS 為 API 自動模式但驅動無效（請在後台 DNS 與節點池選擇具體廠商驅動）',
        'dns_api_credentials_missing' => 'DNS 為 API 自動模式但未保存 API 憑證，無法同步 pool / 租戶 CNAME',
        'dns_zone_id_missing' => 'Cloudflare 驅動未配置 Zone ID，API 同步將失敗',
        'node_pool_empty' => '沒有啟用的邊緣節點，憑證無法部署、pool DNS 無 A 記錄可同步',
        'node_no_public_ip' => '有 :count 台啟用節點但未配置公網 IP（public_ip / extra_ips），pool A 記錄與 A 池 CNAME 預檢將無法工作',
        'cert_validating_stuck' => ':total 張憑證超過 :hours 小時仍為 validating（可能 ACME 任務卡住）：:names',
        'queue_failed_jobs' => '隊列有 :count 條 failed_jobs，請執行 php artisan queue:failed 排查',
        'queue_backlog' => '隊列積壓 :pending 條（閾值 :threshold），請確認 redi-queue / queue:work 在運行',
        'queue_stale' => '有 :count 條隊列任務超過 :hours 小時未執行，可能 worker 未運行',
        'cert_domain_name_drift' => '憑證 #:id domain_name 與域名不一致且未能自動對齊：記錄 :recorded，應為 :canonical',
        'cert_validating_stuck_more' => ' …等共 :total 個',
    ],
];
