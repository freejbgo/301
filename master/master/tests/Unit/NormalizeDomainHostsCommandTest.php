<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NormalizeDomainHostsCommandTest extends TestCase
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
            $table->string('original_url');
            $table->string('original_url_hash')->nullable();
            $table->string('skip_url')->nullable();
            $table->unsignedTinyInteger('status')->default(2);
            $table->timestamps();
        });
    }

    public function test_clears_invalid_skip_url_without_model_validation(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'legacy.example',
            'original_url_hash' => hash('sha256', 'legacy.example'),
            'skip_url' => 'javascript:alert(1)',
            'status' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('redi:normalize-domain-hosts');
        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('redi_admin_domain', [
            'original_url' => 'legacy.example',
            'skip_url' => '',
        ]);
    }
}
