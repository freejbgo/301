<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateEdgePoolAndCertTables extends Migration
{
    public function up()
    {
        Schema::create('redi_platform_dns', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary()->default(1);
            $table->string('edge_zone', 255)->default('')->comment('平台主域名，如 123.com');
            $table->string('pool_host', 63)->default('pool')->comment('池子主机名，FQDN=pool.edge_zone');
            $table->string('dns_mode', 16)->default('manual')->comment('manual|api');
            $table->string('dns_driver', 32)->default('manual')->comment('manual|cloudflare|...');
            $table->text('api_credentials')->nullable()->comment('加密 JSON');
            $table->string('dns_zone_id', 128)->nullable();
            $table->timestamps();
        });

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('public_ip', 45)->unique();
            $table->boolean('enabled')->default(true);
            $table->string('api_token_hash', 255)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_tenant_edge', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('admin_id')->unique();
            $table->string('token', 32);
            $table->string('edge_fqdn', 255);
            $table->timestamp('dns_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id');
            $table->unsignedInteger('admin_id');
            $table->string('domain_name', 255);
            $table->string('status', 32)->default('none');
            $table->string('method', 16)->default('http-01');
            $table->timestamp('expires_at')->nullable();
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique('domain_id');
            $table->index(['admin_id', 'status']);
        });

        Schema::create('redi_cert_deployments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('cert_id');
            $table->unsignedInteger('node_id');
            $table->string('status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
            $table->unique(['cert_id', 'node_id']);
        });

        Schema::create('redi_dns_sync_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('action', 64);
            $table->string('status', 16);
            $table->text('message')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('redi_admin_domain') && ! Schema::hasColumn('redi_admin_domain', 'force_https')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                $table->boolean('force_https')->default(false)->after('status');
            });
        }

        DB::table('redi_platform_dns')->insert([
            'id' => 1,
            'edge_zone' => '',
            'pool_host' => 'pool',
            'dns_mode' => 'manual',
            'dns_driver' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        if (Schema::hasTable('redi_admin_domain') && Schema::hasColumn('redi_admin_domain', 'force_https')) {
            Schema::table('redi_admin_domain', function (Blueprint $table) {
                $table->dropColumn('force_https');
            });
        }
        Schema::dropIfExists('redi_dns_sync_logs');
        Schema::dropIfExists('redi_cert_deployments');
        Schema::dropIfExists('redi_domain_certs');
        Schema::dropIfExists('redi_tenant_edge');
        Schema::dropIfExists('redi_nodes');
        Schema::dropIfExists('redi_platform_dns');
    }
}
