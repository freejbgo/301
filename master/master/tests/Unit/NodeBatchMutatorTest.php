<?php

namespace Tests\Unit;

use App\Models\RediNode;
use App\Services\NodeBatchMutator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeBatchMutatorTest extends TestCase
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

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('public_ip');
            $table->boolean('enabled')->default(true);
            $table->string('api_token_hash')->nullable();
            $table->timestamps();
        });

        RediNode::query()->insert([
            ['id' => 1, 'name' => 'n1', 'public_ip' => '1.2.3.4', 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'n2', 'public_ip' => '5.6.7.8', 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_batch_disable_nodes(): void
    {
        $mutator = new NodeBatchMutator();
        $result = $mutator->setEnabled([1, 2], false);

        $this->assertSame(2, $result['updated']);
        $this->assertFalse((bool) RediNode::query()->find(1)->enabled);
    }
}
