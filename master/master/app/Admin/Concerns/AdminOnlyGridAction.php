<?php

namespace App\Admin\Concerns;

use Dcat\Admin\Admin;

trait AdminOnlyGridAction
{
    protected function ensureAdministratorResponse()
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-admin-node.actions.rotate_token.error_admin'));
        }

        return null;
    }
}
