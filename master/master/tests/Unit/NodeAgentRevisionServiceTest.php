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
}
