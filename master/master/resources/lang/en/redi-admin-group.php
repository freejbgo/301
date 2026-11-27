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
        'name_required'      => 'Group name cannot be empty',
        'delete_has_domains' => 'This group still has :count domain(s), so it cannot be deleted. Open the domain list and move those domains to another group, or delete the domains first, then delete this group.',
        'owner_invalid'      => 'Select a valid tenant account',
        'owner_not_tenant'   => 'Groups cannot be owned by the platform administrator account',
    ],
    'actions' => [
        'batch_enable' => [
            'title' => 'Batch enable',
            'error_none' => 'No groups to enable',
            'success' => 'Enabled :count group(s)',
        ],
        'batch_disable' => [
            'title' => 'Batch disable',
            'confirm' => 'Disabled groups cannot be chosen when adding domains. Continue?',
            'error_none' => 'No groups to disable',
            'success' => 'Disabled :count group(s)',
        ],
        'batch_delete' => [
            'title' => 'Batch delete',
            'confirm' => 'A group that still has domains cannot be deleted. Move those domains to another group, or delete the domains first. Continue deleting empty groups?',
            'error_none' => 'No groups to delete',
            'success' => 'Deleted :count group(s)',
        ],
        'batch_common' => [
            'skipped' => ' (skipped :count unchanged)',
            'partial_errors' => ':count more failed',
        ],
    ],
    'batch_mutator' => [
        'errors' => [
            'status_invalid' => 'Invalid status',
            'no_groups' => 'No groups to process',
            'row_save' => ':label: :reason',
            'row_delete' => ':label: :reason',
            'save_failed' => 'Save failed',
        ],
    ],
    'url_set' => [
        'confirm_title'   => 'Submit this form?',
        'confirm_content' => 'Redirect URL will apply to all domains in this group.',
        'field_skip_url'  => 'Redirect URL',
        'success'         => 'Redirect URL updated for domains in this group',
        'success_all_skipped' => ':count domain(s) in this group already use that redirect URL; no changes needed',
        'error_group_id'  => 'Invalid group id',
        'error_skip_url'  => 'Invalid redirect URL',
        'error_group'     => 'Invalid group',
        'error_forbidden' => 'Invalid group or not owned by your account',
    ],
];
