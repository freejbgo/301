<?php

namespace Tests\Unit;

use App\Models\RediAcmeChallenge;
use App\Services\AcmeChallengeCleanup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcmeChallengeCleanupTest extends TestCase
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
            $table->string('token')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('redi_acme_challenge_node_sync', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('domain_name');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_purge_for_hosts_removes_challenge_and_sync_rows(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'acme.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);
        \DB::table('redi_acme_challenge_node_sync')->insert([
            'node_id' => 1,
            'domain_name' => 'acme.example',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AcmeChallengeCleanup::purgeForHosts(['acme.example', 'ignored.invalid!!!']);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => 'acme.example']);
        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', ['domain_name' => 'acme.example']);
    }

    public function test_purge_accepts_url_with_scheme(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'acme.example',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        AcmeChallengeCleanup::purgeForHosts(['https://acme.example/']);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => 'acme.example']);
    }

    public function test_purge_matches_legacy_scheme_stored_domain_name(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => 'https://acme.example/',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);
        \DB::table('redi_acme_challenge_node_sync')->insert([
            'node_id' => 1,
            'domain_name' => 'https://acme.example/',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AcmeChallengeCleanup::purgeForHosts(['acme.example']);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => 'https://acme.example/']);
        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', ['domain_name' => 'https://acme.example/']);
    }

    public function test_purge_matches_exact_unpublishable_stored_name(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => '1.2.3.4',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);
        \DB::table('redi_acme_challenge_node_sync')->insert([
            'node_id' => 1,
            'domain_name' => '1.2.3.4',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AcmeChallengeCleanup::purgeForHosts(['1.2.3.4']);

        $this->assertDatabaseMissing('redi_acme_challenges', ['domain_name' => '1.2.3.4']);
        $this->assertDatabaseMissing('redi_acme_challenge_node_sync', ['domain_name' => '1.2.3.4']);
    }

    public function test_purge_skips_when_no_canonical_or_exact_match(): void
    {
        RediAcmeChallenge::create([
            'domain_name' => '1.2.3.4',
            'token' => 't',
            'response_body' => 'b',
            'expires_at' => now()->addHour(),
        ]);

        AcmeChallengeCleanup::purgeForHosts(['not-a-valid-host!!!']);

        $this->assertDatabaseHas('redi_acme_challenges', ['domain_name' => '1.2.3.4']);
    }
}
