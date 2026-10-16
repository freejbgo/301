<?php

namespace Tests\Unit;

use App\Models\RediAcmeChallenge;
use App\Services\NodeAgentRevisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeAgentRevisionServiceTest extends TestCase
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

        Schema::create('redi_acme_challenges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('domain_name')->unique();
            $table->string('token');
            $table->text('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('redi_admin_domain', function (Blueprint $table) {
            $table->increments('id');
            $table->string('original_url')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('force_https')->default(false);
            $table->timestamps();
        });

        Schema::create('redi_domain_certs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('domain_id')->nullable();
            $table->string('domain_name');
            $table->string('status')->default('active');
            $table->text('certificate')->nullable();
            $table->timestamps();
        });
    }

    public function test_challenges_revision_ignores_empty_token_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'empty.example',
            'token' => '',
            'response_body' => '',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();
        RediAcmeChallenge::where('domain_name', 'empty.example')->delete();
        $b = $svc->build();

        $this->assertSame($a['challenges_revision'], $b['challenges_revision']);
    }

    public function test_challenges_revision_ignores_malformed_response_body_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'bad.example',
            'token' => 'ok-token',
            'response_body' => "bo\r\ndy",
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallenge::create([
            'domain_name' => 'good.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();
        RediAcmeChallenge::where('domain_name', 'bad.example')->delete();
        $b = $svc->build();

        $this->assertSame($a['challenges_revision'], $b['challenges_revision']);
    }

    public function test_rules_revision_ignores_invalid_hosts(): void
    {
        \DB::table('redi_admin_domain')->insert([
            'original_url' => 'good.example',
            'status' => 1,
            'force_https' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $svc = new NodeAgentRevisionService();
        $a = $svc->build();

        \DB::table('redi_admin_domain')->insert([
            'original_url' => '1.2.3.4',
            'status' => 1,
            'force_https' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b = $svc->build();

        $this->assertSame($a['rules_revision'], $b['rules_revision']);
    }
}
