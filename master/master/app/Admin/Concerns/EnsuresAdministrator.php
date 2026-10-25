<?php

namespace App\Admin\Concerns;

use Dcat\Admin\Admin;

trait EnsuresAdministrator
{
    protected function ensureAdministrator(): void
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            abort(403, __('redi-auth.admin_only'));
        }
    }
}
