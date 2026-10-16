<?php

namespace App\Admin\Controllers;

use Dcat\Admin\Http\Controllers\AuthController as BaseAuthController;

class AuthController extends BaseAuthController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware('throttle:10,1')->only('postLogin');
    }
}
