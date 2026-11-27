<?php

namespace Tests\Unit;

use App\Services\GroupBatchMutator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroupBatchMutatorTest extends TestCase
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

        Schema::create('redi_admin_group', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id');
            $table->string('admin_name')->nullable();
            $table->string('name');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });

        for ($i = 1; $i <= 205; $i++) {
            \DB::table('redi_admin_group')->insert([
                'id' => $i,
                'admin_id' => 10,
                'admin_name' => 'a',
                'name' => 'g'.$i,
                'status' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function test_truncates_over_cap(): void
    {
        $ids = range(1, 205);
        $mutator = new GroupBatchMutator();
        $result = $mutator->setStatusOwned($ids, 1, 10, false);

        $this->assertSame(200, $result['updated']);
        $this->assertSame(5, $result['truncated']);
    }

    public function test_delete_of_group_with_domains_explains_why(): void
    {
        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id')->default(0);
            $table->unsignedInteger('group_id')->default(0);
            $table->string('domain')->nullable();
        });
        \DB::table('redi_admin_domain')->insert([
            'id' => 1,
            'admin_id' => 99,
            'group_id' => 1,
            'domain' => 'a.example',
        ]);

        $mutator = new GroupBatchMutator();
        $blocked = $mutator->deleteOwned([1], 10, false);

        $this->assertSame(0, $blocked['updated']);
        $this->assertNotEmpty($blocked['errors']);
        $this->assertStringContainsString('g1', $blocked['errors'][0]);
        $this->assertStringContainsString('还有 1 个域名', $blocked['errors'][0]);
        $this->assertStringContainsString('改到别的分组', $blocked['errors'][0]);
        $this->assertStringNotContainsString('The given data was invalid', $blocked['errors'][0]);
        $this->assertSame(1, \DB::table('redi_admin_group')->where('id', 1)->count());

        $empty = $mutator->deleteOwned([2], 10, false);
        $this->assertSame(1, $empty['updated']);
        $this->assertSame([], $empty['errors']);
    }
}
