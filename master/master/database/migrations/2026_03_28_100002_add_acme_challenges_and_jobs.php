<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAcmeChallengesAndJobs extends Migration
{
    public function up()
    {
        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->string('acme_contact_email', 255)->nullable()->after('dns_zone_id');
            $table->boolean('acme_staging')->default(false)->after('acme_contact_email');
        });

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->increments('id');
            $table->string('domain_name', 255);
            $table->string('token', 128);
            $table->text('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique('domain_name');
            $table->index('expires_at');
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('redi_acme_challenges');
        if (Schema::hasTable('jobs')) {
            Schema::dropIfExists('jobs');
        }
        Schema::table('redi_platform_dns', function (Blueprint $table) {
            $table->dropColumn(['acme_contact_email', 'acme_staging']);
        });
    }
}
