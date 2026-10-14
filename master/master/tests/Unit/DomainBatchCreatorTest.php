<?php

namespace Tests\Unit;

use App\Services\DomainBatchCreator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainBatchCreatorTest extends TestCase
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

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('admin_id');
            $table->string('admin_name')->nullable();
            $table->string('original_url');
            $table->string('original_url_hash', 64)->nullable();
            $table->string('skip_url');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedInteger('skip_num')->default(0);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        \DB::table('redi_admin_group')->insert([
            'id' => 1,
            'admin_id' => 10,
            'admin_name' => 'tenant-a',
            'name' => 'g1',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rejects_group_not_owned_by_admin_id(): void
    {
        $creator = new DomainBatchCreator();
        $result = $creator->createFromTextLines(
            99,
            'other',
            1,
            1,
            "foo.com,https://example.com\n",
            null
        );

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString('分组无效', $result['errors'][0] ?? '');
    }

    public function test_imports_for_matching_tenant(): void
    {
        $creator = new DomainBatchCreator();
        $result = $creator->createFromTextLines(
            10,
            'tenant-a',
            1,
            1,
            "foo.com,https://example.com\n",
            null
        );

        $this->assertSame(1, $result['imported']);
        $row = \DB::table('redi_admin_domain')->where('original_url', 'foo.com')->first();
        $this->assertNotNull($row);
        $this->assertSame(10, (int) $row->admin_id);
    }
}
