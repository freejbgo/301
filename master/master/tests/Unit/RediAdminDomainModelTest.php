<?php

namespace Tests\Unit;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediAdminDomainModelTest extends TestCase
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

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain_name')->unique();
            $table->string('token');
            $table->text('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('admin_name')->nullable();
            $table->string('original_url');
            $table->string('original_url_hash', 64)->nullable();
            $table->string('skip_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('skip_num')->default(0);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name');
            $table->string('status')->default('none');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function test_certificate_list_filter_none(): void
    {
        RediAdminDomain::query()->insert([
            'group_id' => 1,
            'admin_id' => 1,
            'original_url' => 'a.com',
            'skip_url' => 'https://x.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $withCert = RediAdminDomain::query()->insertGetId([
            'group_id' => 1,
            'admin_id' => 1,
            'original_url' => 'b.com',
            'skip_url' => 'https://x.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $withCert,
            'domain_name' => 'b.com',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = RediAdminDomain::query()->tap(function ($q) {
            RediAdminDomain::applyCertificateListFilter($q, 'none');
        })->pluck('original_url')->all();

        $this->assertSame(['a.com'], $ids);
    }

    public function test_rejects_invalid_skip_url_on_save(): void
    {
        $domain = new RediAdminDomain();
        $domain->group_id = 1;
        $domain->admin_id = 1;
        $domain->original_url = 'valid.example';
        $domain->skip_url = 'javascript:alert(1)';

        $this->expectException(ValidationException::class);
        $domain->save();
    }

    public function test_normalizes_valid_skip_url(): void
    {
        $domain = new RediAdminDomain();
        $domain->group_id = 1;
        $domain->admin_id = 1;
        $domain->original_url = 'valid.example';
        $domain->skip_url = 'https://dest.example/path';

        $domain->save();

        $this->assertSame('https://dest.example/path', $domain->fresh()->skip_url);
    }

    public function test_disabling_domain_clears_acme_challenges(): void
    {
        $domain = RediAdminDomain::create([
            'group_id' => 1,
            'admin_id' => 1,
            'original_url' => 'off.example',
            'skip_url' => 'https://dest.example/',
            'status' => 1,
        ]);

        \DB::table('redi_acme_challenges')->insert([
            'domain_name' => 'off.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_acme_challenge_node_sync')->insert([
            'node_id' => 1,
            'domain_name' => 'off.example',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domain->status = 2;
        $domain->save();

        $this->assertSame(0, \DB::table('redi_acme_challenges')->count());
        $this->assertSame(0, \DB::table('redi_acme_challenge_node_sync')->count());
    }

    public function test_disabling_domain_clears_cert_deployments(): void
    {
        $domain = RediAdminDomain::create([
            'group_id' => 1,
            'admin_id' => 1,
            'original_url' => 'off.example',
            'skip_url' => 'https://dest.example/',
            'status' => 1,
        ]);

        $certId = \DB::table('redi_domain_certs')->insertGetId([
            'domain_id' => $domain->id,
            'domain_name' => 'off.example',
            'status' => RediDomainCert::STATUS_DEPLOYING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nodeId = \DB::table('redi_nodes')->insertGetId([
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_cert_deployments')->insert([
            'cert_id' => $certId,
            'node_id' => $nodeId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domain->status = 2;
        $domain->save();

        $this->assertSame(0, \DB::table('redi_cert_deployments')->count());
    }
}
