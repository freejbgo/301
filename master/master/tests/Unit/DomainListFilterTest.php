<?php

namespace Tests\Unit;

use App\Services\DomainListFilter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainListFilterTest extends TestCase
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
            $table->unsignedInteger('admin_id');
            $table->string('original_url');
            $table->string('skip_url')->default('https://example.com');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        \DB::table('redi_admin_domain')->insert([
            ['id' => 1, 'admin_id' => 1, 'group_id' => 1, 'original_url' => 'a.com', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'admin_id' => 1, 'group_id' => 2, 'original_url' => 'b.com', 'status' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_limited_ids_respects_status_filter(): void
    {
        $request = Request::create('/admin/domain', 'GET', ['status' => 2]);
        $ids = DomainListFilter::limitedIds($request);

        $this->assertSame([2], $ids);
    }
}
