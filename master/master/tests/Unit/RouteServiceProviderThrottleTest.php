<?php

namespace Tests\Unit;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

class RouteServiceProviderThrottleTest extends TestCase
{
    public function test_throttle_ip_key_rejects_control_chars(): void
    {
        $provider = new RouteServiceProvider($this->app);
        $method = new \ReflectionMethod(RouteServiceProvider::class, 'throttleIpKey');
        $method->setAccessible(true);

        $request = Request::create('http://localhost/', 'GET', server: ['REMOTE_ADDR' => "203.0.113.1\r\n"]);
        $this->assertSame('invalid-ip', $method->invoke($provider, $request));
    }
}
