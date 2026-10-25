<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\RediHealthReport;
use App\Services\TenantOpsSummary;
use App\Support\AdminTenantContext;
use App\Support\OpsDisplaySanitizer;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Layout\Row;
use Dcat\Admin\Widgets\Card;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        $content->header(__('redi-home.labels.header'));

        $user = Admin::user();
        if ($user && method_exists($user, 'isAdministrator') && $user->isAdministrator()) {
            $summary = RediHealthReport::summary();
            $issues = RediHealthReport::collectIssues(7);
            $issueHtml = $issues === []
                ? '<p class="text-success mb-0">'.e(__('redi-home.labels.health_ok')).'</p>'
                : '<ul class="mb-0">'.implode('', array_map(function ($row) {
                    $class = $row['level'] === 'critical' ? 'text-danger' : 'text-warning';

                    return '<li class="'.e($class).'">'.e(OpsDisplaySanitizer::field($row['message'] ?? '', '—', 300)).'</li>';
                }, array_slice($issues, 0, 8))).'</ul>';

            if (count($issues) > 8) {
                $issueHtml .= '<p class="text-muted mt-2 mb-0">'.e(__('redi-home.labels.health_more', ['count' => count($issues) - 8])).'</p>';
            }

            $content->row(function (Row $row) use ($summary) {
                $row->column(2, Card::make(__('redi-home.cards.enabled_nodes'), (string) $summary['enabled_nodes']));
                $row->column(2, Card::make(__('redi-home.cards.stale_nodes'), (string) $summary['stale_nodes']));
                $row->column(2, Card::make(__('redi-home.cards.pending_deploys'), (string) $summary['pending_deploys']));
                $row->column(2, Card::make(__('redi-home.cards.failed_deploys'), (string) $summary['failed_deploys']));
                $row->column(2, Card::make(__('redi-home.cards.queue_pending'), (string) $summary['queue_pending']));
                $row->column(2, Card::make(__('redi-home.cards.queue_failed'), (string) $summary['queue_failed']));
            });

            $requeueConfirm = json_encode(__('redi-home.labels.requeue_confirm'), JSON_UNESCAPED_UNICODE);
            $content->row(function (Row $row) use ($issueHtml, $requeueConfirm) {
                $requeueUrl = admin_url('ops/requeue-failed-deployments');
                $token = csrf_token();
                $opsHtml = $issueHtml
                    .'<form method="post" action="'.e($requeueUrl).'" class="mt-3" onsubmit="return confirm('.$requeueConfirm.');">'
                    .'<input type="hidden" name="_token" value="'.e($token).'">'
                    .'<input type="hidden" name="_redirect" value="'.e(admin_base_path('/')).'">'
                    .'<button type="submit" class="btn btn-warning btn-sm">'.e(__('redi-home.labels.requeue_btn')).'</button>'
                    .'<span class="text-muted ml-2">'.e(__('redi-home.labels.requeue_hint')).'</span>'
                    .'</form>';
                $row->column(12, Card::make(__('redi-home.labels.ops_card'), $opsHtml));
            });
        } elseif ($user) {
            $stats = TenantOpsSummary::forAdmin(AdminTenantContext::sessionAdminId());
            $tips = '<ul class="mb-0">'
                .'<li>'.e(__('redi-home.labels.tenant_stats_enabled', [
                    'enabled' => (int) $stats['domains_enabled'],
                    'disabled' => (int) $stats['domains_disabled'],
                ])).'</li>'
                .'<li>'.e(__('redi-home.labels.tenant_stats_cert_active', ['count' => (int) $stats['certs_active']])).'</li>'
                .'<li>'.e(__('redi-home.labels.tenant_stats_cert_pending', ['count' => (int) $stats['certs_needs_attention']])).'</li>'
                .'<li>'.e(__('redi-home.labels.tenant_stats_expiring', ['count' => (int) $stats['certs_expiring_soon']])).'</li>'
                .'</ul>'
                .'<p class="text-muted mt-2 mb-0">'.e(__('redi-home.labels.tenant_tip')).'</p>';

            $content->row(function (Row $row) use ($stats) {
                $row->column(3, Card::make(__('redi-home.cards.domains_enabled'), (string) $stats['domains_enabled']));
                $row->column(3, Card::make(__('redi-home.cards.certs_active'), (string) $stats['certs_active']));
                $row->column(3, Card::make(__('redi-home.cards.certs_attention'), (string) $stats['certs_needs_attention']));
                $row->column(3, Card::make(__('redi-home.cards.certs_expiring'), (string) $stats['certs_expiring_soon']));
            });

            $content->row(function (Row $row) use ($tips) {
                $row->column(12, Card::make(__('redi-home.labels.tenant_overview'), $tips));
            });
        }

        return $content;
    }
}
