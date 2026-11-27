<?php

namespace App\Admin\Controllers;

use Dcat\Admin\Http\Controllers\AuthController as BaseAuthController;

class AuthController extends BaseAuthController
{
    public function __construct()
    {
        // Illuminate\Routing\Controller has no constructor. parent::__construct()
        // fatals with "Cannot call constructor" and the login page returns 500.
        $this->middleware('throttle:10,1')->only('postLogin');
    }
}
