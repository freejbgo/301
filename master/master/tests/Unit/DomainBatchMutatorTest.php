<?php

namespace Tests\Unit;

use App\Models\RediAdminDomain;
use App\Models\RediDomainCert;
use App\Services\DomainBatchMutator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainBatchMutatorTest extends TestCase
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

        Schema::create('redi_admin_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->string('admin_name')->nullable();
            $table->string('name');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('admin_id');
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

        \DB::table('redi_admin_group')->insert([
            ['id' => 1, 'admin_id' => 10, 'admin_name' => 'a', 'name' => 'g1', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'admin_id' => 10, 'admin_name' => 'a', 'name' => 'g2', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        RediAdminDomain::query()->insert([
            [
                'id' => 1,
                'group_id' => 1,
                'admin_id' => 10,
                'original_url' => 'a.com',
                'skip_url' => 'https://old.example.com',
                'status' => 2,
                'force_https' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'group_id' => 1,
                'admin_id' => 20,
                'original_url' => 'b.com',
                'skip_url' => 'https://other.example.com',
                'status' => 1,
                'force_https' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_disable_owned_domains(): void
    {
        RediAdminDomain::query()->where('id', 1)->update(['status' => 1]);

        $mutator = new DomainBatchMutator();
        $result = $mutator->setStatusOwned([1, 2], 2, 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(2, (int) RediAdminDomain::query()->find(1)->status);
        $this->assertSame(1, (int) RediAdminDomain::query()->find(2)->status);
    }

    public function test_enable_skips_other_tenant(): void
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->setStatusOwned([1, 2], 1, 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, (int) RediAdminDomain::query()->find(1)->status);
    }

    public function test_set_skip_url_owned(): void
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->setSkipUrlOwned([1], 'https://new.example.com/path', 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('https://new.example.com/path', RediAdminDomain::query()->find(1)->skip_url);
    }

    public function test_move_group_within_tenant(): void
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->moveGroupOwned([1], 2, 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(2, (int) RediAdminDomain::query()->find(1)->group_id);
    }

    public function test_delete_owned_domain(): void
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->deleteOwned([1], 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertNull(RediAdminDomain::query()->find(1));
    }

    public function test_force_https_requires_active_cert(): void
    {
        $mutator = new DomainBatchMutator();
        $result = $mutator->setForceHttpsOwned([1], '1', 10, false);

        $this->assertSame(0, $result['updated']);
        $this->assertNotEmpty($result['errors']);

        RediDomainCert::query()->insert([
            'domain_id' => 1,
            'admin_id' => 10,
            'domain_name' => 'a.com',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $mutator->setForceHttpsOwned([1], '1', 10, false);
        $this->assertSame(1, $result['updated']);
        $this->assertTrue((bool) RediAdminDomain::query()->find(1)->force_https);
    }

    public function test_apply_edits_counts_unique_domains(): void
    {
        RediDomainCert::query()->insert([
            'domain_id' => 1,
            'admin_id' => 10,
            'domain_name' => 'a.com',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mutator = new DomainBatchMutator();
        $result = $mutator->applyEditsOwned(
            [1],
            10,
            'https://new.example.com/path',
            2,
            '1',
            false
        );

        $this->assertSame(1, $result['updated']);
        $this->assertSame(2, (int) RediAdminDomain::query()->find(1)->group_id);
        $this->assertSame('https://new.example.com/path', RediAdminDomain::query()->find(1)->skip_url);
        $this->assertTrue((bool) RediAdminDomain::query()->find(1)->force_https);
    }

    public function test_apply_edits_skipped_counts_unique_domains(): void
    {
        RediDomainCert::query()->insert([
            'domain_id' => 1,
            'admin_id' => 10,
            'status' => RediDomainCert::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        RediAdminDomain::query()->where('id', 1)->update([
            'group_id' => 2,
            'skip_url' => 'https://new.example.com/path',
            'force_https' => true,
        ]);

        $mutator = new DomainBatchMutator();
        $result = $mutator->applyEditsOwned(
            [1],
            10,
            'https://new.example.com/path',
            2,
            '1',
            false
        );

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_set_skip_url_for_group_uses_model_save(): void
    {
        RediAdminDomain::query()->where('id', 1)->update(['skip_url' => 'https://old.example.com']);

        $mutator = new DomainBatchMutator();
        $result = $mutator->setSkipUrlForGroupOwned(1, 'https://group-wide.example.com', 10, false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame('https://group-wide.example.com', RediAdminDomain::query()->find(1)->skip_url);
    }
}
