<?php

namespace App\Admin\Concerns;

use Dcat\Admin\Admin;

trait EnsuresAdministrator
{
    protected function ensureAdministrator(): void
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            abort(403, '仅超级管理员可操作');
        }
    }
}
