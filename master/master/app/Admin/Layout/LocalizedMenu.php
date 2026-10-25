<?php

namespace App\Admin\Layout;

use App\Support\AdminMenuTitle;
use Dcat\Admin\Layout\Menu;

class LocalizedMenu extends Menu
{
    /**
     * @param  array<string, mixed>  $item
     */
    public function translateMenuItem(array $item): string
    {
        $title = (string) ($item['title'] ?? '');
        $uri = isset($item['uri']) ? (string) $item['uri'] : null;

        return AdminMenuTitle::resolve($title, $uri);
    }
}
