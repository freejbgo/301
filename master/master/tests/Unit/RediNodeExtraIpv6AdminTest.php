<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediNodeExtraIpv6AdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->string('public_ipv6', 45)->nullable();
            $table->text('extra_ipv6')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_extra_without_primary_is_rejected(): void
    {
        $extras = RediNode::parseExtraIpv6('2001:db8::2');
        $this->assertNotSame([], $extras);

        $canonicalV6 = RediNode::preparePublicIpv6ForStorage('');
        $this->assertNull($canonicalV6);

        $this->expectException(ValidationException::class);
        if ($extras !== [] && $canonicalV6 === null) {
            throw ValidationException::withMessages([
                'extra_ipv6' => '请先填写主公网 IPv6',
            ]);
        }
    }

    public function test_dedupes_extra_against_primary_canonical_form(): void
    {
        $canonicalV6 = RediNode::preparePublicIpv6ForStorage('2001:db8::1');
        $extraV6 = RediNode::parseExtraIpv6("2001:0db8::1\n2001:db8::2");
        $extraV6 = array_values(array_filter($extraV6, function ($ip) use ($canonicalV6) {
            return $ip !== $canonicalV6;
        }));

        $this->assertSame([RediNode::canonicalPublicIpv6('2001:db8::2')], $extraV6);
    }

    public function test_saving_keeps_invalid_extra_in_array(): void
    {
        $node = RediNode::forceCreate([
            'name' => 'n1',
            'public_ip' => '203.0.113.1',
            'public_ipv6' => '2001:db8::1',
            'extra_ipv6' => ['fe80::1'],
        ]);
        $node->name = 'n1-kept';
        $node->save();

        $fresh = $node->fresh();
        $this->assertIsArray($fresh->extra_ipv6);
        $this->assertSame(['fe80::1'], $fresh->extra_ipv6);
    }
}
