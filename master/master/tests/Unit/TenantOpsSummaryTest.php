<?php

namespace Tests\Unit;

use App\Services\TenantOpsSummary;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantOpsSummaryTest extends TestCase
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

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_counts_domains_for_tenant(): void
    {
        \DB::table('redi_admin_domain')->insert([
            ['admin_id' => 10, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['admin_id' => 10, 'status' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['admin_id' => 20, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $stats = TenantOpsSummary::forAdmin(10);
        $this->assertSame(1, $stats['domains_enabled']);
        $this->assertSame(1, $stats['domains_disabled']);
    }
}
