<?php

return [
    'fields' => [
        'group_id'         => 'Group',
        'status'           => 'Status',
        'default_skip_url' => 'Default redirect URL',
        'lines'            => 'Domain lines',
    ],
    'options' => [
        'status' => [1 => 'Enabled', 2 => 'Disabled'],
    ],
    'help' => [
        'status'           => 'When batch-enabling, each line must already point DNS to the platform',
        'default_skip_url' => 'Option A: set this URL and put only origin hosts on each line below.',
        'lines'            => 'Up to :max lines per batch. Placeholder text disappears when you focus the field.',
    ],
    'placeholder' => "Instructions (placeholder clears on focus)\n· Up to :max lines (one host per line; blank lines ignored; # comments)\n· Option A: set default redirect above, one origin host per line, e.g. example.com\n· Option B: origin,redirect per line (comma/pipe/tab), e.g. foo.com,https://a.com\n· Do not include http:// on origin hosts; duplicates in your account are skipped\n· Group and status above apply to the whole batch\n\nExamples:\nexample.com\nfoo.com,https://target.example.com\n# more domains…",
    'messages' => [
        'group_invalid'     => 'Invalid group',
        'group_wrong_owner' => 'Invalid group or not owned by this account',
        'nothing_imported'  => 'No domains were added',
        'summary'           => 'Added :imported, skipped duplicates :skipped.',
        'partial_errors'    => "Some lines were not saved:\n:errors",
    ],
    'errors' => [
        'status_invalid'       => 'Status must be enabled or disabled',
        'group_invalid'        => 'Invalid group or group does not belong to this account',
        'no_lines'             => 'Enter at least one domain line',
        'too_many_lines'       => 'At most :max domains per batch (submitted :count); split into smaller batches.',
        'row_original_empty'   => 'Row :row: origin hostname cannot be empty or contain a path',
        'row_original_invalid' => 'Row :row (:label): invalid origin hostname',
        'row_skip_missing'     => 'Row :row (:label): missing redirect URL; set default redirect or use "host,url" on the line',
        'row_dns_required'     => 'Row :row (:label): enable requires CNAME to :edge or A to a node public IP',
        'row_write_failed'       => 'Row :row (:label): write failed',
    ],
];
