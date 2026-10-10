<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNodeChallengeSync extends Migration
{
    public function up()
    {
        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name', 255);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['node_id', 'domain_name']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('redi_acme_challenge_node_sync');
    }
}
