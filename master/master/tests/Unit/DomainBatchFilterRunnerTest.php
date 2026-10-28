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
}
