<?php

namespace Tests\Unit;

use App\Models\RediLagranSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RediLagranSettingTest extends TestCase
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
        Schema::create('redi_lagran_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('enabled')->default(true);
            $table->boolean('syn_ack')->default(true);
            $table->boolean('ack')->default(true);
            $table->unsignedTinyInteger('window_scale')->default(7);
            $table->unsignedTinyInteger('confusion_times')->default(0);
            $table->unsignedInteger('split_number')->default(7);
            $table->unsignedSmallInteger('window_after_split')->default(28960);
            $table->unsignedSmallInteger('window_confusion_last')->default(65535);
            $table->unsignedSmallInteger('window_80')->default(1);
            $table->unsignedSmallInteger('window_443')->default(1);
            $table->timestamps();
        });
    }

    public function test_missing_table_uses_production_defaults(): void
    {
        Schema::drop('redi_lagran_settings');
        $payload = RediLagranSetting::current()->toAgentPayload();
        $this->assertTrue($payload['enabled']);
        $this->assertSame(7, $payload['window_scale']);
        $this->assertSame(0, $payload['confusion_times']);
        $this->assertSame(7, $payload['split_number']);
        $this->assertSame(28960, $payload['window_after_split']);
        $this->assertSame(65535, $payload['window_confusion_last']);
        $this->assertSame(['80' => 1, '443' => 1], $payload['window']);
    }

    public function test_store_rejects_out_of_range_window_scale(): void
    {
        $this->expectException(ValidationException::class);
        RediLagranSetting::store($this->input(['window_scale' => 15]));
    }

    public function test_store_accepts_switch_strings(): void
    {
        $row = RediLagranSetting::store($this->input([
            'enabled' => 'on',
            'ack' => '0',
            'window_80' => '9',
        ]));
        $payload = $row->toAgentPayload();
        $this->assertTrue($payload['enabled']);
        $this->assertFalse($payload['ack']);
        $this->assertSame(9, $payload['window']['80']);
        $this->assertSame(1, RediLagranSetting::query()->count());
    }

    private function input(array $overrides): array
    {
        return array_merge([
            'enabled' => '1',
            'syn_ack' => '1',
            'ack' => '1',
            'window_scale' => '7',
            'confusion_times' => '0',
            'split_number' => '7',
            'window_after_split' => '28960',
            'window_confusion_last' => '65535',
            'window_80' => '1',
            'window_443' => '1',
        ], $overrides);
    }
}
