<?php

namespace App\Admin\Controllers;

use App\Admin\Concerns\EnsuresAdministrator;
use App\Admin\Forms\PlatformDnsSettings;
use App\Http\Controllers\Controller;
use App\Models\RediPlatformDns;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Alert;
use Dcat\Admin\Widgets\Card;
use Dcat\Admin\Widgets\Table;
use App\Services\EdgeDnsManualReference;
use App\Services\EdgeDnsSyncService;
use Illuminate\Http\Request;
use App\Support\OpsDisplaySanitizer;
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
            ? '请先填写平台主域名并添加节点。'
            : (new Table(['类型', '主机名 / FQDN', '记录值'], $tables['pool']))->render();

        $tenantHtml = $tables['tenant'] === []
            ? '配置平台主域名后，租户 edge 记录将显示于此。'
            : (new Table(['类型', '主机记录（FQDN）', '指向'], $tables['tenant']))->render();

        $manualIntro = Alert::make(
            '当前为<strong>手动解析</strong>：请复制下方记录，到你方 DNS 服务商（Cloudflare、阿里云等）控制台自行添加或更新；本系统不会调用 DNS API。',
            '说明'
        )->info();

        $apiIntro = Alert::make(
            '当前为 <strong>API 自动</strong>：保存驱动与凭证后，可点击同步将 pool 的多条 A 与各租户 CNAME 写入厂商。',
            '说明'
        )->success();

        $syncBlock = '';
        if ($apiMode) {
            $syncUrl = admin_url('platform-dns/sync');
            $syncBlock = <<<HTML
<p><button type="button" class="btn btn-primary" id="redi-dns-sync">同步 Pool / 租户 CNAME 到 DNS 厂商</button></p>
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
      if (res.status) { Dcat.success(res.message); } else { Dcat.warning(res.message || '未执行同步'); }
    }).fail(function (xhr) {
      Dcat.loading(false);
      var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '同步失败';
      Dcat.error(msg);
    });
  });
});
</script>
HTML;
        }

        $card = Card::make('DNS 与节点池', new PlatformDnsSettings());
        $poolCard = Card::make('Pool：节点 A 记录参考', ($apiMode ? $apiIntro : $manualIntro).$syncBlock.$poolHtml);
        $tenantCard = Card::make('租户 edge：token → pool（CNAME）参考', $tenantHtml);

        return $content
            ->title('DNS 与节点池')
            ->description('模型 2：客户域 CNAME → 租户 token；token CNAME → pool；pool 多条 A → 节点')
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
                'message' => '当前为手动解析模式：请按页面 Pool / 租户参考表在 DNS 服务商自行配置，无需同步。',
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
                'message' => 'DNS 同步失败：'.OpsDisplaySanitizer::logSnippet($e->getMessage(), 500),
            ], 422);
        }

        return response()->json(['status' => true, 'message' => 'DNS 已同步到厂商']);
    }
}
