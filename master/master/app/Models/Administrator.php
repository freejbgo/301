<?php

namespace App\Models;

use Dcat\Admin\Models\Administrator as BaseAdministrator;

class Administrator extends BaseAdministrator
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password',
        'name',
        'avatar',
        'remember_token',
        'locale',
    ];
}
