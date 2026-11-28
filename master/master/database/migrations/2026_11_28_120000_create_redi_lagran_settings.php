<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateRediLagranSettings extends Migration
{
    public function up()
    {
        if (Schema::hasTable('redi_lagran_settings')) {
            return;
        }

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

        $now = now();
        DB::table('redi_lagran_settings')->insert([
            'enabled' => 1,
            'syn_ack' => 1,
            'ack' => 1,
            'window_scale' => 7,
            'confusion_times' => 0,
            'split_number' => 7,
            'window_after_split' => 28960,
            'window_confusion_last' => 65535,
            'window_80' => 1,
            'window_443' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('redi_lagran_settings');
    }
}
