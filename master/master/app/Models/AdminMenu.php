<?php

namespace App\Models;

use Dcat\Admin\Models\Menu;

class AdminMenu extends Menu
{
    public function fetchAll()
    {
        return parent::fetchAll()->reject(function ($node) {
            return trim((string) $node->uri, '/') === 'auth/extensions';
        })->values();
    }
}
