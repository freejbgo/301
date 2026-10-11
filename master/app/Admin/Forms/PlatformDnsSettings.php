<?php

namespace App\Admin\Forms;

use App\Models\RediPlatformDns;
use App\Services\TenantEdgeService;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;

class PlatformDnsSettings extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $platform = RediPlatformDns::instance();
        $oldZone = trim($platform->edge_zone ?? '');
        $platform->edge_zone = trim($input['edge_zone'] ?? '');
        $platform->pool_host = trim($input['pool_host'] ?? 'pool') ?: 'pool';
        $platform->dns_mode = $input['dns_mode'] ?? 'manual';
        $platform->dns_driver = $input['dns_driver'] ?? 'manual';
        $platform->dns_zone_id = $input['dns_zone_id'] ?? null;
        $platform->acme_contact_email = trim($input['acme_contact_email'] ?? '') ?: null;
        $platform->acme_staging = ! empty($input['acme_staging']);

        if (! empty($input['api_key'])) {
            $platform->setApiCredentialsArray([
                'api_key' => $input['api_key'],
                'api_secret' => $input['api_secret'] ?? '',
            ]);
        }

        $platform->save();

        $newZone = trim($platform->edge_zone ?? '');
        if ($oldZone !== $newZone && $newZone !== '') {
            TenantEdgeService::rebuildAllEdgeFqdn($newZone);
        }

        return $this->response()->success('已保存')->refresh();
    }

    public function form()
    {
        $this->text('edge_zone', '平台主域名')->required()->help('例：123.com（你拥有 DNS 解析权的域名）');
        $this->text('pool_host', 'Pool 主机名')->default('pool')->help('最终 FQDN：pool.平台主域名');
        $this->radio('dns_mode', '解析模式')->options(['manual' => '手动', 'api' => 'API 自动'])->default('manual')
            ->help('手动：在下方「解析参考」表按记录在 DNS 服务商面板自行添加，无需点同步。API 自动：保存凭证后可一键同步 pool A 与各租户 token→pool CNAME。');
        $this->select('dns_driver', 'DNS 驱动')->options([
            'manual' => '手动',
            'cloudflare' => 'Cloudflare',
            'aliyun_cn' => '阿里云中国',
            'aliyun_intl' => '阿里云国际',
            'dnspod_cn' => 'DNSPod 中国',
            'dnspod_intl' => 'DNSPod 国际',
            'dns_com' => 'dns.com',
        ])->default('manual');
        $this->text('dns_zone_id', 'Zone ID')->help('Cloudflare 必填；其他厂商可留空');
        $this->text('api_key', 'API Key / Token')->help('Cloudflare=API Token；阿里云=AccessKey ID；DNSPod=API ID；dns.com=API Key。留空不修改');
        $this->password('api_secret', 'API Secret')->help('阿里云=AccessKey Secret；DNSPod=Token；dns.com=API Secret');
        $this->email('acme_contact_email', 'ACME 联系邮箱')->help('Let\'s Encrypt 注册邮箱');
        $this->switch('acme_staging', 'ACME 测试环境')->help('勾选则使用 Let\'s Encrypt Staging');
    }

    public function default()
    {
        $p = RediPlatformDns::instance();

        return [
            'edge_zone' => $p->edge_zone,
            'pool_host' => $p->pool_host,
            'dns_mode' => $p->dns_mode,
            'dns_driver' => $p->dns_driver,
            'dns_zone_id' => $p->dns_zone_id,
            'acme_contact_email' => $p->acme_contact_email,
            'acme_staging' => $p->acme_staging,
        ];
    }
}
