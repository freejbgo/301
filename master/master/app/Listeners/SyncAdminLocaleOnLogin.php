<?php

namespace App\Listeners;

use App\Http\Middleware\SetAdminLocale;
use Illuminate\Auth\Events\Login;

class SyncAdminLocaleOnLogin
{
    public function handle(Login $event): void
    {
        if (($event->guard ?? null) !== 'admin') {
            return;
        }

        SetAdminLocale::syncForAuthenticatedUser($event->user);
    }
}
