<?php

namespace Tests\Feature;

use App\Models\RediAdminDomain;
use App\Services\DomainBatchFilterRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 批量域名：筛选批量 + 租户隔离（不依赖 Dcat HTTP，覆盖 BatchFilterDomains 同款 Request→Runner 路径）。
 */
class DomainBatchOperationsTest extends TestCase
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
        config(['redi.require_dns_pointing_to_enable' => false]);

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain_name');
            $table->string('token')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->unsignedInteger('admin_id');
            $table->string('domain_name')->default('');
            $table->string('status')->default('none');
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id');
            $table->string('original_url');
            $table->string('original_url_hash', 64)->nullable();
            $table->string('skip_url')->default('https://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        RediAdminDomain::query()->insert([
            [
                'id' => 1,
                'admin_id' => 10,
                'original_url' => 'tenant-a.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'admin_id' => 20,
                'original_url' => 'tenant-b.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_filter_batch_disable_scopes_to_session_admin_even_when_broad_filter(): void
    {
        $request = Request::create('/admin/domains', 'GET', [
            'original_url' => 'tenant',
        ]);
        $runner = new DomainBatchFilterRunner();
        $out = $runner->run('disable', $request, 10);

        $this->assertTrue($out['ok']);
        $this->assertSame(2, (int) RediAdminDomain::query()->find(1)->status);
        $this->assertSame(1, (int) RediAdminDomain::query()->find(2)->status);
    }

    public function test_filter_batch_delete_rejected_without_list_filter(): void
    {
        $runner = new DomainBatchFilterRunner();
        $out = $runner->run('delete', Request::create('/admin/domains', 'GET'), 10);

        $this->assertFalse($out['ok']);
        $this->assertSame(2, (int) RediAdminDomain::query()->count());
    }

    public function test_filter_batch_disable_only_matching_hosts(): void
    {
        $request = Request::create('/admin/domains', 'GET', [
            'original_url' => 'tenant-a',
        ]);
        $runner = new DomainBatchFilterRunner();
        $out = $runner->run('disable', $request, 10);

        $this->assertTrue($out['ok']);
        $this->assertSame(2, (int) RediAdminDomain::query()->find(1)->status);
        $this->assertSame(1, (int) RediAdminDomain::query()->find(2)->status);
    }
}
