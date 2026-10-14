<?php

namespace Tests\Unit;

use App\Models\RediAdminDomain;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediAdminDomainModelTest extends TestCase
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
            $table->string('original_url_hash', 64)->nullable();
            $table->string('skip_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('skip_num')->default(0);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });
    }

    public function test_rejects_invalid_skip_url_on_save(): void
    {
        $domain = new RediAdminDomain();
        $domain->group_id = 1;
        $domain->admin_id = 1;
        $domain->original_url = 'valid.example';
        $domain->skip_url = 'javascript:alert(1)';

        $this->expectException(ValidationException::class);
        $domain->save();
    }

    public function test_normalizes_valid_skip_url(): void
    {
        $domain = new RediAdminDomain();
        $domain->group_id = 1;
        $domain->admin_id = 1;
        $domain->original_url = 'valid.example';
        $domain->skip_url = 'https://dest.example/path';

        $domain->save();

        $this->assertSame('https://dest.example/path', $domain->fresh()->skip_url);
    }
}
