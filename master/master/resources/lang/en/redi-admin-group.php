<?php

return [
    'labels' => [
        'title'           => 'Groups',
        'batch_redirect'  => 'Batch edit redirects',
        'batch_modal'     => 'Batch edit redirects',
        'no_tenant'       => 'Create a tenant admin account (non-administrator role) before creating groups.',
    ],
    'fields' => [
        'name'            => 'Name',
        'status'          => 'Status',
        'admin_name'      => 'Owner account',
        'owner_admin_id'  => 'Tenant account',
    ],
    'options' => [
        'status' => [1 => 'Enabled', 2 => 'Disabled'],
    ],
    'help' => [
        'owner_admin_id' => 'Platform admins must assign a tenant when creating a group.',
    ],
    'validation' => [
        'delete_has_domains' => 'This group still has :count domain(s). Move or delete them first.',
        'owner_invalid'      => 'Select a valid tenant account',
        'owner_not_tenant'   => 'Groups cannot be owned by the platform administrator account',
    ],
    'url_set' => [
        'confirm_title'   => 'Submit this form?',
        'confirm_content' => 'Redirect URL will apply to all domains in this group.',
        'field_skip_url'  => 'Redirect URL',
        'success'         => 'Redirect URL updated for domains in this group',
        'error_group_id'  => 'Invalid group id',
        'error_skip_url'  => 'Invalid redirect URL',
        'error_group'     => 'Invalid group',
        'error_forbidden' => 'Invalid group or not owned by your account',
    ],
];
