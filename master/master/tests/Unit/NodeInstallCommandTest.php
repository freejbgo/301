<?php

namespace Tests\Unit;

use App\Models\RediNode;
use App\Support\NodeInstallCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NodeInstallCommandTest extends TestCase
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
        DB::purge('sqlite');

        Schema::create('redi_nodes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('public_ip', 45)->nullable();
            $table->string('api_token_hash')->nullable();
            $table->text('api_token')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function test_command_embeds_master_ip_install_key_and_token(): void
    {
        $dir = sys_get_temp_dir().'/redi-node-install-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nexample\n-----END OPENSSH PRIVATE KEY-----\n";
        file_put_contents($dir.'/node-install.key', $pem);
        file_put_contents($dir.'/master-ip', "203.0.113.10\n");
        config([
            'redi.node_install_key_path' => $dir.'/node-install.key',
            'redi.node_install_master_ip_path' => $dir.'/master-ip',
            'app.url' => 'http://198.51.100.8',
        ]);

        $command = NodeInstallCommand::command('12.abcDEF');

        $this->assertStringContainsString("--master-ip '203.0.113.10'", $command);
        $this->assertStringContainsString('--install-key \''.base64_encode($pem).'\'', $command);
        $this->assertStringContainsString("--token '12.abcDEF'", $command);
        $this->assertStringContainsString('bash node.sh', $command);

        $node = new RediNode();
        $node->name = 'edge-1';
        $node->public_ip = '203.0.113.20';
        $node->enabled = true;
        $node->save();
        $plain = $node->issueApiTokenPlain();
        $node->save();

        $this->assertSame($plain, $node->fresh()->revealApiToken());
        $this->assertNotSame($plain, $node->fresh()->getRawOriginal('api_token'));
        $this->assertTrue($node->fresh()->verifyApiToken($plain));
    }

    public function test_command_uses_placeholders_when_key_and_ip_are_missing(): void
    {
        config([
            'redi.node_install_key_path' => '/tmp/redi-missing-node-install-key',
            'redi.node_install_master_ip_path' => '/tmp/redi-missing-master-ip',
            'app.url' => 'http://localhost',
        ]);
        $this->app['request']->headers->set('HOST', 'localhost');

        $command = NodeInstallCommand::command('Node Token');

        $this->assertStringContainsString("--master-ip '主控IP'", $command);
        $this->assertStringContainsString("--install-key '安装密钥'", $command);
        $this->assertStringContainsString("--token 'Node Token'", $command);
        $this->assertFalse(NodeInstallCommand::hasInstallKey());
    }

    public function test_install_placeholder_and_help_follow_each_locale(): void
    {
        $root = dirname(__DIR__, 2).'/resources/lang';
        $zh = require $root.'/zh_CN/redi-admin-node.php';
        $tw = require $root.'/zh_TW/redi-admin-node.php';
        $en = require $root.'/en/redi-admin-node.php';

        $this->assertSame('节点Token', $zh['install']['token_placeholder']);
        $this->assertStringContainsString('节点Token', $zh['install']['help']);
        $this->assertStringContainsString('节点「查看 Token」', $zh['install']['help']);

        $this->assertSame('節點Token', $tw['install']['token_placeholder']);
        $this->assertStringContainsString('節點Token', $tw['install']['help']);
        $this->assertStringContainsString('節點「查看 Token」', $tw['install']['help']);

        $this->assertSame('Node Token', $en['install']['token_placeholder']);
        $this->assertStringContainsString('Node Token', $en['install']['help']);
        $this->assertStringContainsString('View token', $en['install']['help']);
    }
}
