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
}
