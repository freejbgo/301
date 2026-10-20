<?php

namespace Tests\Unit;

use App\Models\RediTenantEdge;
use App\Services\TenantEdgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantEdgeServiceTest extends TestCase
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

        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->string('token');
            $table->string('edge_fqdn')->nullable();
            $table->timestamp('dns_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_rebuild_skips_invalid_zone(): void
    {
        $edge = new RediTenantEdge();
        $edge->forceFill([
            'admin_id' => 1,
            'token' => 'abc',
            'edge_fqdn' => 'abc.old.com',
        ]);
        $edge->save();

        TenantEdgeService::rebuildAllEdgeFqdn('1.2.3.4');

        $this->assertSame('abc.old.com', RediTenantEdge::query()->first()->edge_fqdn);
    }

    public function test_rebuild_updates_fqdn_under_valid_zone(): void
    {
        $edge = new RediTenantEdge();
        $edge->forceFill([
            'admin_id' => 1,
            'token' => 'tok',
            'edge_fqdn' => 'tok.old.example',
        ]);
        $edge->save();

        TenantEdgeService::rebuildAllEdgeFqdn('example.com');

        $this->assertSame('tok.example.com', RediTenantEdge::query()->first()->edge_fqdn);
    }

    public function test_invalidate_all_dns_synced_at_clears_timestamps(): void
    {
        $edge = new RediTenantEdge();
        $edge->forceFill([
            'admin_id' => 1,
            'token' => 'tok',
            'edge_fqdn' => 'tok.example.com',
            'dns_synced_at' => now(),
        ]);
        $edge->save();

        TenantEdgeService::invalidateAllDnsSyncedAt();

        $this->assertNull(RediTenantEdge::query()->first()->dns_synced_at);
    }
}
