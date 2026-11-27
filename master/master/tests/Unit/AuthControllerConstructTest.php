<?php

namespace Tests\Unit;

use App\Admin\Controllers\AuthController;
use Tests\TestCase;

class AuthControllerConstructTest extends TestCase
{
    public function test_login_controller_can_be_constructed(): void
    {
        $controller = $this->app->make(AuthController::class);

        $this->assertInstanceOf(AuthController::class, $controller);
        $middleware = $controller->getMiddleware();
        $this->assertNotEmpty($middleware);
        $this->assertSame('throttle:10,1', $middleware[0]['middleware']);
    }
}
