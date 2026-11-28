<?php

return [
    'page' => [
        'title' => 'Lagran',
        'description' => 'One policy for every node. Online nodes reload it after you save',
    ],
    'cards' => [
        'settings' => 'Lagran',
    ],
    'sections' => [
        'windows' => 'Windows',
        'confusion' => 'Extra ACKs',
    ],
    'fields' => [
        'enabled' => 'Enable lagran',
        'syn_ack' => 'Rewrite SYN+ACK',
        'ack' => 'Rewrite pure ACK',
        'window_80' => 'Window for port 80',
        'window_443' => 'Window for port 443',
        'split_number' => 'Pure ACK count',
        'window_after_split' => 'Window after the count',
        'confusion_times' => 'Extra ACK count',
        'window_scale' => 'Window Scale',
        'window_confusion_last' => 'Window of the last extra ACK',
    ],
    'help' => [
        'intro' => 'After you save, online nodes pick this up within about 5 seconds and reload lagran in process. You do not log in to each machine, and the node process is not restarted. Ports 80 and 443 have separate windows. REDI_LAGRAN_DISABLED=1 on a machine still forces lagran off. Queue numbers are not set here. Older node binaries keep using their local config.json.',
        'enabled' => 'When this is off, the node removes the OUTPUT rules it installed.',
        'syn_ack' => 'Outbound handshake packets. When this is off, their window is left unchanged.',
        'ack' => 'Pure ACKs with no payload. When this is off, the count no longer changes their window.',
        'window_80' => 'Window for source port 80, from 1 to 65535. Used by SYN+ACK, extra ACKs, and pure ACKs that have not reached the count.',
        'window_443' => 'Window for source port 443. Separate from port 80.',
        'split_number' => 'After this many pure ACKs on one flow, use the window below. Default 7.',
        'window_after_split' => 'Window used once the pure ACK count is reached. Default 28960.',
        'confusion_times' => 'Extra ACKs sent for each SYN+ACK. Default 0 sends none. Values above 0 need cap_net_raw.',
        'window_scale' => 'Written only on extra ACKs. Range 0–14, default 7. Unused while the extra ACK count is 0.',
        'window_confusion_last' => 'Window of the last extra ACK. Default 65535. Unused while the extra ACK count is 0.',
    ],
    'messages' => [
        'saved' => 'Saved. Online nodes reload lagran on their next sync.',
        'forbidden' => 'Only a super administrator can change Lagran settings',
        'invalid' => 'Invalid settings',
        'invalid_bool' => 'Invalid switch value',
        'missing_table' => 'The lagran settings table is missing. Run php artisan migrate',
    ],
    'invalid' => [
        'window_scale' => 'Window Scale must be an integer from 0 to 14',
        'confusion_times' => 'Extra ACK count must be an integer from 0 to 64',
        'split_number' => 'Pure ACK count must be an integer from 0 to 1000000',
        'window_after_split' => 'Window after the count must be an integer from 1 to 65535',
        'window_confusion_last' => 'Last extra ACK window must be an integer from 1 to 65535',
        'window_80' => 'Port 80 window must be an integer from 1 to 65535',
        'window_443' => 'Port 443 window must be an integer from 1 to 65535',
    ],
];
