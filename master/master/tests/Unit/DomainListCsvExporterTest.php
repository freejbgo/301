<?php

namespace Tests\Unit;

use App\Models\RediDomainCert;
use App\Services\DomainListCsvExporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainListCsvExporterTest extends TestCase
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
            $table->unsignedInteger('group_id')->default(1);
            $table->unsignedInteger('admin_id')->default(1);
            $table->string('admin_name')->nullable();
            $table->string('original_url');
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
    }

    public function test_filtered_query_respects_cert_filter(): void
    {
        $a = \DB::table('redi_admin_domain')->insertGetId([
            'original_url' => 'a.com',
            'skip_url' => 'https://x.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'b.com',
            'skip_url' => 'https://x.com',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('redi_domain_certs')->insert([
            'domain_id' => $a,
            'domain_name' => 'a.com',
            'status' => RediDomainCert::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = Request::create('/admin/domain/export', 'GET', ['cert_filter' => 'active']);
        $urls = DomainListCsvExporter::filteredQuery($request)->pluck('original_url')->all();

        $this->assertSame(['a.com'], $urls);
    }

    public function test_dns_export_unknown_without_edge(): void
    {
        Schema::create('redi_platform_dns', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('edge_zone')->default('');
            $table->string('pool_host')->default('pool');
            $table->timestamps();
        });
        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id')->unique();
            $table->string('token', 32);
            $table->string('edge_fqdn', 255)->nullable();
            $table->timestamps();
        });
        \DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => '',
            'pool_host' => 'pool',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domain = new \App\Models\RediAdminDomain();
        $domain->forceFill([
            'id' => 1,
            'admin_id' => 99,
            'original_url' => 'x.com',
            'skip_url' => 'https://d.com',
            'status' => 1,
        ]);

        [$target, $ok] = DomainListCsvExporter::dnsExportColumnsForDomain($domain);
        $this->assertSame('', $target);
        $this->assertSame('unknown', $ok);
    }

    public function test_dns_export_unknown_for_invalid_original_url(): void
    {
        $domain = new \App\Models\RediAdminDomain();
        $domain->forceFill([
            'id' => 2,
            'admin_id' => 1,
            'original_url' => '1.2.3.4',
            'skip_url' => 'https://d.com',
            'status' => 1,
        ]);

        [, $ok] = DomainListCsvExporter::dnsExportColumnsForDomain($domain);
        $this->assertSame('unknown', $ok);
    }
}
