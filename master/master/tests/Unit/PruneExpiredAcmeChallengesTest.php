<?php

namespace Tests\Unit;

use App\Models\RediAcmeChallenge;
use App\Models\RediAcmeChallengeNodeSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneExpiredAcmeChallengesTest extends TestCase
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
            $table->increments('id');
            $table->string('domain_name');
            $table->string('token', 128)->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at');
            $table->timestamps();
        });
    }

    public function test_prune_succeeds_when_nothing_to_prune(): void
    {
        $this->artisan('redi:prune-acme-challenges')->assertExitCode(0);
    }

    public function test_prune_removes_expired_challenge_and_sync_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'old.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->subMinute(),
        ]);
        RediAcmeChallengeNodeSync::create([
            'node_id' => 1,
            'domain_name' => 'old.example',
            'synced_at' => now(),
        ]);

        $this->artisan('redi:prune-acme-challenges')->assertExitCode(0);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => 'old.example']);
        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', ['domain_name' => 'old.example']);
    }

    public function test_prune_removes_invalid_empty_challenge_and_sync_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'empty.example',
            'token' => '',
            'response_body' => '',
            'expires_at' => now()->addHour(),
        ]);
        RediAcmeChallengeNodeSync::create([
            'node_id' => 2,
            'domain_name' => 'empty.example',
            'synced_at' => now(),
        ]);

        $this->artisan('redi:prune-acme-challenges')->assertExitCode(0);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => 'empty.example']);
        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', ['domain_name' => 'empty.example']);
    }
}
