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
}
