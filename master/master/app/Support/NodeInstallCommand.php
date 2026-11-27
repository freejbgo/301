<?php

namespace App\Support;

class NodeInstallCommand
{
    public static function command(string $token): string
    {
        $ip = self::masterIp();
        $key = self::installKeyB64();

        return 'curl -fsSL https://github.com/freejbgo/301/raw/main/node.sh -o node.sh && chmod +x node.sh && bash node.sh'
            .' --master-ip '.self::shellArg($ip !== '' ? $ip : '主控IP')
            .' --install-key '.self::shellArg($key !== '' ? $key : '安装密钥')
            .' --token '.self::shellArg($token);
    }

    public static function hasInstallKey(): bool
    {
        return self::installKeyB64() !== '';
    }

    public static function publishCommand(): string
    {
        return 'install -o nginx -g nginx -m 640 /root/301-node-install.key /opt/301/master/storage/app/node-install.key';
    }

    public static function dialogHtml(string $token): string
    {
        $command = e(self::command($token));
        $tokenEsc = e($token);

        return '<div style="text-align:left">'
            .'<div style="margin-bottom:6px">'.e(__('redi-admin-node.install.dialog_token')).'</div>'
            .'<textarea readonly rows="2" onclick="this.select()" style="width:100%;font-family:monospace">'.$tokenEsc.'</textarea>'
            .'<div style="margin:10px 0 6px">'.e(__('redi-admin-node.install.dialog_command')).'</div>'
            .'<textarea readonly rows="6" onclick="this.select()" style="width:100%;font-family:monospace">'.$command.'</textarea>'
            .'</div>';
    }

    public static function masterIp(): string
    {
        $fromFile = self::readTrimmed(self::masterIpPath());
        if (self::isIpv4($fromFile)) {
            return $fromFile;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && self::isIpv4($appHost)) {
            return $appHost;
        }

        $requestHost = request()->getHost();
        if (self::isIpv4($requestHost)) {
            return $requestHost;
        }

        return '';
    }

    public static function installKeyB64(): string
    {
        foreach (self::keyPaths() as $path) {
            if (! is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            if (str_contains($raw, 'PRIVATE KEY')) {
                return base64_encode($raw);
            }
            $compact = preg_replace('/\s+/', '', $raw) ?? '';
            if ($compact !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact) === 1) {
                return $compact;
            }
        }

        return '';
    }

    /** @return list<string> */
    public static function keyPaths(): array
    {
        $configured = trim((string) config('redi.node_install_key_path', ''));

        return array_values(array_filter([
            $configured !== '' ? $configured : storage_path('app/node-install.key'),
            '/root/301-node-install.key',
        ]));
    }

    public static function masterIpPath(): string
    {
        $configured = trim((string) config('redi.node_install_master_ip_path', ''));

        return $configured !== '' ? $configured : storage_path('app/node-install-master-ip');
    }

    protected static function readTrimmed(string $path): string
    {
        if (! is_readable($path)) {
            return '';
        }
        $raw = file_get_contents($path);

        return is_string($raw) ? trim($raw) : '';
    }

    protected static function isIpv4(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    protected static function shellArg(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
