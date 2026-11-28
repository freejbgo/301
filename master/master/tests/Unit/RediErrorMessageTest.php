<?php

namespace Tests\Unit;

use App\Support\RediErrorMessage;
use Tests\TestCase;

class RediErrorMessageTest extends TestCase
{
    public function test_returns_english_canonical_message(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame(
            'Invalid domain hostname; cannot issue certificate',
            RediErrorMessage::get('cert.invalid_hostname')
        );
    }

    public function test_admin_locale_rewrites_stored_cert_error(): void
    {
        app()->setLocale('zh_CN');
        $stored = RediErrorMessage::get('cert.material_unavailable_skip');
        $shown = RediErrorMessage::localize('cert.material_unavailable_skip', $stored);

        $this->assertStringContainsString('申请证书', $shown);
        $this->assertStringNotContainsString('Certificate material', $shown);
    }

    public function test_admin_locale_keeps_host_and_translates_appended_ipv6_sentence(): void
    {
        app()->setLocale('zh_TW');
        $stored = RediErrorMessage::get('acme.http01_timeout', ['seconds' => 120])
            .'aa.example.com/.well-known/acme-challenge/tok'
            .RediErrorMessage::get('acme.http01_timeout_ipv6');
        $shown = RediErrorMessage::localize(null, $stored);

        $this->assertStringContainsString('120', $shown);
        $this->assertStringContainsString('aa.example.com/.well-known/acme-challenge/tok', $shown);
        $this->assertStringContainsString('IPv6', $shown);
        $this->assertStringNotContainsString('HTTP-01 not ready', $shown);
        $this->assertStringNotContainsString('With Pool AAAA', $shown);
    }

    public function test_cname_target_is_kept_in_translation(): void
    {
        app()->setLocale('zh_CN');
        $stored = RediErrorMessage::get('cert.cname_not_pointing', ['target' => 'edge.example.com']);
        $shown = RediErrorMessage::localize('cert.cname_not_pointing', $stored);

        $this->assertStringContainsString('edge.example.com', $shown);
        $this->assertStringContainsString('申请证书', $shown);
    }

    public function test_english_locale_keeps_the_stored_sentence(): void
    {
        app()->setLocale('en');
        $stored = RediErrorMessage::get('cert.all_nodes_failed');

        $this->assertSame($stored, RediErrorMessage::localize('cert.all_nodes_failed', $stored));
    }

    public function test_unknown_text_is_left_unchanged(): void
    {
        app()->setLocale('zh_CN');

        $this->assertSame('node said disk full', RediErrorMessage::localize(null, 'node said disk full'));
    }
}
