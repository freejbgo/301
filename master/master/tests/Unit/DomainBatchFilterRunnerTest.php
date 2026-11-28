<?php

namespace Tests\Unit;

use App\Services\DomainBatchFilterRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainBatchFilterRunnerTest extends TestCase
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
            $table->string('original_url');
            $table->string('skip_url')->default('https://example.com');
            $table->timestamps();
        });

        \DB::table('redi_admin_domain')->insert([
            'id' => 1,
            'admin_id' => 1,
            'status' => 1,
            'original_url' => 'a.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_delete_without_filter_is_rejected(): void
    {
        $runner = new DomainBatchFilterRunner();
        $request = Request::create('/admin/domain', 'GET');
        $out = $runner->run('delete', $request, 1);

        $this->assertFalse($out['ok']);
        $this->assertSame(1, (int) \DB::table('redi_admin_domain')->count());
    }

    public function test_enable_without_filter_is_rejected(): void
    {
        $runner = new DomainBatchFilterRunner();
        $request = Request::create('/admin/domain', 'GET');
        $out = $runner->run('enable', $request, 1);

        $this->assertFalse($out['ok']);
    }

    public function test_redeploy_requires_platform_administrator(): void
    {
        $runner = new DomainBatchFilterRunner();
        $request = Request::create('/admin/domain', 'POST', ['selected_ids' => '1']);
        $out = $runner->run('redeploy', $request, 1);

        $this->assertFalse($out['ok']);
        $this->assertSame(__('redi-auth.admin_only'), $out['message']);
    }

    public function test_force_https_on_without_filter_is_rejected(): void
    {
        $runner = new DomainBatchFilterRunner();
        $request = Request::create('/admin/domain', 'GET');
        $out = $runner->run('force_https_on', $request, 1);

        $this->assertFalse($out['ok']);
    }

    public function test_force_https_on_enables_active_certificate_only(): void
    {
        Schema::table('redi_admin_domain', function (Blueprint $table) {
            $table->string('original_url_hash', 64)->nullable();
            $table->boolean('force_https')->default(false);
        });
        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->string('domain_name')->default('');
            $table->string('status')->default('none');
            $table->timestamps();
        });
        \DB::table('redi_admin_domain')->insert([
            'id' => 2,
            'admin_id' => 1,
            'status' => 1,
            'original_url' => 'b.com',
            'skip_url' => 'https://example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => 1,
            'domain_name' => 'a.com',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runner = new DomainBatchFilterRunner();
        $mixed = $runner->run('force_https_on', Request::create('/admin/domain', 'GET', [
            'selected_ids' => '1,2',
        ]), 1);

        $this->assertTrue($mixed['ok'], $mixed['message']);
        $this->assertSame(1, $mixed['count']);
        $this->assertStringContainsString('b.com', $mixed['message']);
        $this->assertStringContainsString('证书尚未生效', $mixed['message']);
        $this->assertSame(1, (int) \DB::table('redi_admin_domain')->where('id', 1)->value('force_https'));
        $this->assertSame(0, (int) \DB::table('redi_admin_domain')->where('id', 2)->value('force_https'));

        $again = $runner->run('force_https_on', Request::create('/admin/domain', 'GET', ['original_url' => 'a.com']), 1);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('已经开启了强制 HTTPS', $again['message']);
    }
}
