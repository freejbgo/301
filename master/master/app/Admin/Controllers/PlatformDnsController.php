<?php

namespace App\Admin\Controllers;

use App\Admin\Concerns\EnsuresAdministrator;
use App\Admin\Forms\PlatformDnsSettings;
use App\Http\Controllers\Controller;
use App\Models\RediPlatformDns;
use App\Services\EdgeDnsManualReference;
use App\Services\EdgeDnsSyncService;
use App\Support\OpsDisplaySanitizer;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Alert;
use Dcat\Admin\Widgets\Card;
use Dcat\Admin\Widgets\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlatformDnsController extends Controller
{
    use EnsuresAdministrator;

    public function index(Content $content)
    {
        $this->ensureAdministrator();

        $platform = RediPlatformDns::instance();
        $apiMode = $platform->isDnsApiMode();
        $tables = EdgeDnsManualReference::tables();

        $poolHtml = $tables['pool'] === []
            ? e(__('redi-platform-dns.empty.pool'))
            : (new Table(__('redi-platform-dns.table.pool_headers'), $tables['pool']))->render();

        $tenantHtml = $tables['tenant'] === []
            ? e(__('redi-platform-dns.empty.tenant'))
            : (new Table(__('redi-platform-dns.table.tenant_headers'), $tables['tenant']))->render();

        $manualIntro = Alert::make(
            __('redi-platform-dns.alerts.manual_body'),
            __('redi-platform-dns.alerts.manual_title')
        )->info();

        $apiIntro = Alert::make(
            __('redi-platform-dns.alerts.api_body'),
            __('redi-platform-dns.alerts.api_title')
        )->success();

        $syncBlock = '';
        if ($apiMode) {
            $syncUrl = admin_url('platform-dns/sync');
            $syncBtnLabel = e(__('redi-platform-dns.sync.button'));
            $syncNotRun = json_encode(__('redi-platform-dns.sync.not_run'), JSON_UNESCAPED_UNICODE);
            $syncFailed = json_encode(__('redi-platform-dns.sync.failed'), JSON_UNESCAPED_UNICODE);
            $syncBlock = <<<HTML
<p><button type="button" class="btn btn-primary" id="redi-dns-sync">{$syncBtnLabel}</button></p>
<script>
Dcat.ready(function () {
  $('#redi-dns-sync').on('click', function () {
    Dcat.loading();
    $.ajax({
      method: 'POST',
      url: '{$syncUrl}',
      data: {_token: Dcat.token}
    }).done(function (res) {
      Dcat.loading(false);
      if (res.status) { Dcat.success(res.message); } else { Dcat.warning(res.message || {$syncNotRun}); }
    }).fail(function (xhr) {
      Dcat.loading(false);
      var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : {$syncFailed};
      Dcat.error(msg);
    });
  });
});
</script>
HTML;
        }

        $card = Card::make(__('redi-platform-dns.cards.settings'), new PlatformDnsSettings());
        $poolCard = Card::make(__('redi-platform-dns.cards.pool'), ($apiMode ? $apiIntro : $manualIntro).$syncBlock.$poolHtml);
        $tenantCard = Card::make(__('redi-platform-dns.cards.tenant'), $tenantHtml);

        return $content
            ->title(__('redi-platform-dns.page.title'))
            ->description(__('redi-platform-dns.page.description'))
            ->row($card)
            ->row($poolCard)
            ->row($tenantCard);
    }

    public function sync(Request $request, EdgeDnsSyncService $syncService)
    {
        $this->ensureAdministrator();

        $platform = RediPlatformDns::instance();
        if (! $platform->isDnsApiMode()) {
            return response()->json([
                'status' => true,
                'message' => __('redi-platform-dns.sync.manual_mode_info'),
            ]);
        }

        try {
            $syncService->syncAll();
        } catch (\Throwable $e) {
            Log::warning('EdgeDnsSync failed', [
                'message' => OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
            ]);

            return response()->json([
                'status' => false,
                'message' => __('redi-platform-dns.sync.failed_prefix').OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
            ], 422);
        }

        return response()->json(['status' => true, 'message' => __('redi-platform-dns.sync.success')]);
    }
}
