<?php

namespace App\Admin\Forms;

use App\Models\RediPlatformDns;
use App\Services\Dns\DnsProviderFactory;
use App\Services\TenantEdgeService;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;

class PlatformDnsSettings extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $user = Admin::user();
        if (! $user || ! method_exists($user, 'isAdministrator') || ! $user->isAdministrator()) {
            return $this->response()->error(__('redi-platform-dns.messages.forbidden'));
        }

        $mode = strtolower(trim((string) ($input['dns_mode'] ?? 'manual')));
        if (! in_array($mode, ['manual', 'api'], true)) {
            return $this->response()->error(__('redi-platform-dns.messages.invalid_dns_mode'));
        }
        $driver = strtolower(trim((string) ($input['dns_driver'] ?? 'manual')));
        if ($mode === 'api' && ($driver === '' || $driver === 'manual')) {
            return $this->response()->error(__('redi-platform-dns.messages.api_driver_required'));
        }
        if ($mode === 'api' && ! DnsProviderFactory::isSupportedApiDriver($driver)) {
            return $this->response()->error(__('redi-platform-dns.messages.unsupported_driver', ['driver' => $driver]));
        }
        if ($mode === 'manual') {
            $driver = 'manual';
        }

        $platform = RediPlatformDns::instance();
        $oldZone = trim($platform->edge_zone ?? '');
        $oldPoolHost = strtolower(trim((string) ($platform->pool_host ?: 'pool')));
        $edgeZone = strtolower(trim((string) ($input['edge_zone'] ?? '')));
        $poolHost = strtolower(trim((string) ($input['pool_host'] ?? 'pool') ?: 'pool'));
        if (OpsDisplaySanitizer::containsControlChars($edgeZone)
            || OpsDisplaySanitizer::containsControlChars($poolHost)) {
            return $this->response()->error(__('redi-platform-dns.messages.control_chars'));
        }
        $edgeZone = HostNameValidator::toCanonicalAscii($edgeZone);
        if ($edgeZone === '' || ! HostNameValidator::isValidHostLabel($edgeZone)) {
            return $this->response()->error(__('redi-platform-dns.messages.invalid_edge_zone'));
        }
        if ($poolHost === '' || str_contains($poolHost, '.')
            || ! HostNameValidator::isValidHostLabel($poolHost)) {
            return $this->response()->error(__('redi-platform-dns.messages.invalid_pool_host'));
        }
        $platform->edge_zone = $edgeZone;
        $platform->pool_host = $poolHost;
        $platform->dns_mode = $mode;
        $platform->dns_driver = $driver;
        $platform->dns_zone_id = $input['dns_zone_id'] ?? null;
        $platform->acme_contact_email = trim($input['acme_contact_email'] ?? '') ?: null;
        $platform->acme_staging = ! empty($input['acme_staging']);
        if (RediPlatformDns::hasPublishPoolAaaaColumn()) {
            $platform->publish_pool_aaaa = filter_var($input['publish_pool_aaaa'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        if (! empty($input['clear_dns_api_credentials'])) {
            $platform->setApiCredentialsArray(null);
        } elseif (! empty($input['api_key'])) {
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
        if ($oldZone === $newZone && $oldPoolHost !== $poolHost) {
            TenantEdgeService::invalidateAllDnsSyncedAt();
        }

        return $this->response()->success(__('redi-platform-dns.messages.saved'))->refresh();
    }

    public function form()
    {
        $dnsModeOptions = trans('redi-platform-dns.options.dns_mode');
        $dnsDriverOptions = trans('redi-platform-dns.options.dns_driver');

        $this->text('edge_zone', __('redi-platform-dns.fields.edge_zone'))->required()
            ->help(__('redi-platform-dns.help.edge_zone'));
        $this->text('pool_host', __('redi-platform-dns.fields.pool_host'))->default('pool')
            ->help(__('redi-platform-dns.help.pool_host'));
        $this->radio('dns_mode', __('redi-platform-dns.fields.dns_mode'))->options($dnsModeOptions)->default('manual')
            ->help(__('redi-platform-dns.help.dns_mode'));
        $this->select('dns_driver', __('redi-platform-dns.fields.dns_driver'))->options($dnsDriverOptions)->default('manual');
        $this->text('dns_zone_id', __('redi-platform-dns.fields.dns_zone_id'))
            ->help(__('redi-platform-dns.help.dns_zone_id'));
        $this->text('api_key', __('redi-platform-dns.fields.api_key'))
            ->help(__('redi-platform-dns.help.api_key'));
        $this->password('api_secret', __('redi-platform-dns.fields.api_secret'))
            ->help(__('redi-platform-dns.help.api_secret'));
        $this->switch('clear_dns_api_credentials', __('redi-platform-dns.fields.clear_dns_api_credentials'))
            ->help(__('redi-platform-dns.help.clear_dns_api_credentials'));
        $this->email('acme_contact_email', __('redi-platform-dns.fields.acme_contact_email'))
            ->help(__('redi-platform-dns.help.acme_contact_email'));
        $this->switch('acme_staging', __('redi-platform-dns.fields.acme_staging'))
            ->help(__('redi-platform-dns.help.acme_staging'));
        if (RediPlatformDns::hasPublishPoolAaaaColumn()) {
            $this->switch('publish_pool_aaaa', __('redi-platform-dns.fields.publish_pool_aaaa'))
                ->default(1)
                ->help(__('redi-platform-dns.help.publish_pool_aaaa'));
        }
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
            'publish_pool_aaaa' => $p->publish_pool_aaaa,
        ];
    }
}
