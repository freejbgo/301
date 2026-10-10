<?php

namespace App\Admin\Controllers;

use App\Admin\Concerns\EnsuresAdministrator;
use App\Admin\Forms\PlatformDnsSettings;
use App\Http\Controllers\Controller;
use App\Models\RediNode;
use App\Models\RediPlatformDns;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Card;
use Dcat\Admin\Widgets\Table;
use App\Services\EdgeDnsSyncService;
use Illuminate\Http\Request;

class PlatformDnsController extends Controller
{
    use EnsuresAdministrator;

    public function index(Content $content)
    {
        $this->ensureAdministrator();

        $platform = RediPlatformDns::instance();
        $poolFqdn = $platform->poolFqdn();
        $ips = RediNode::enabled()->orderBy('id')->pluck('public_ip')->all();

        $manualHtml = '';
        if ($poolFqdn !== '') {
            $rows = array_map(fn ($ip) => ['A', $poolFqdn, $ip], $ips);
            $manualHtml = (new Table(['类型', '主机名', '记录值'], $rows ?: [['A', $poolFqdn, '（请先在节点管理添加 IP）']]))->render();
        }

        $syncUrl = admin_url('platform-dns/sync');
        $syncBtn = <<<HTML
<p><button type="button" class="btn btn-primary" id="redi-dns-sync">同步 Pool / 租户 CNAME（API 模式）</button></p>
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
      if (res.status) { Dcat.success(res.message); } else { Dcat.error(res.message || '同步失败'); }
    }).fail(function (xhr) {
      Dcat.loading(false);
      var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '同步失败';
      Dcat.error(msg);
    });
  });
});
</script>
HTML;

        $card = Card::make('DNS 与节点池', new PlatformDnsSettings());
        $poolCard = Card::make('Pool 手动解析参考', $syncBtn.($manualHtml ?: '请先填写平台主域名并添加节点。'));

        return $content
            ->title('DNS 与节点池')
            ->description('模型 2：租户 CNAME → pool；仅在 pool 上挂节点 A')
            ->row($card)
            ->row($poolCard);
    }

    public function sync(Request $request, EdgeDnsSyncService $syncService)
    {
        $this->ensureAdministrator();

        try {
            $syncService->syncAll();
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => true, 'message' => 'DNS 同步完成']);
    }
}
