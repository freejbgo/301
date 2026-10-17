<?php

namespace Tests\Unit;

use App\Models\RediNode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RediNodeNoteSanitizeTest extends TestCase
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
            $table->id();
            $table->string('name')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->text('note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_note_strips_control_chars_on_save(): void
    {
        $node = RediNode::query()->create([
            'name' => 'edge-1',
            'public_ip' => '203.0.113.10',
            'note' => "ops\r\nnote",
        ]);

        $this->assertSame('opsnote', $node->fresh()->note);
    }

    public function test_name_strips_control_chars_on_save(): void
    {
        $node = RediNode::query()->create([
            'name' => "edge\r\n1",
            'public_ip' => '203.0.113.11',
        ]);

        $this->assertSame('edge1', $node->fresh()->name);
    }
}
