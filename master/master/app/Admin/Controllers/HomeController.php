<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Services\RediHealthReport;
use App\Services\TenantOpsSummary;
use App\Support\AdminTenantContext;
use Dcat\Admin\Admin;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Layout\Row;
use Dcat\Admin\Widgets\Card;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        $content->header('301 跳转系统');

        $user = Admin::user();
        if ($user && method_exists($user, 'isAdministrator') && $user->isAdministrator()) {
            $summary = RediHealthReport::summary();
            $issues = RediHealthReport::collectIssues(7);
            $issueHtml = $issues === []
                ? '<p class="text-success mb-0">redi:health 当前无待处理项。</p>'
                : '<ul class="mb-0">'.implode('', array_map(function ($row) {
                    $class = $row['level'] === 'critical' ? 'text-danger' : 'text-warning';
                    return '<li class="'.e($class).'">'.e($row['message']).'</li>';
                }, array_slice($issues, 0, 8))).'</ul>';

            if (count($issues) > 8) {
                $issueHtml .= '<p class="text-muted mt-2 mb-0">另有 '.(count($issues) - 8).' 项，请执行 <code>php artisan redi:health</code></p>';
            }

            $content->row(function (Row $row) use ($summary) {
                $row->column(2, Card::make('启用节点', (string) $summary['enabled_nodes']));
                $row->column(2, Card::make('心跳异常', (string) $summary['stale_nodes']));
                $row->column(2, Card::make('证书待部署', (string) $summary['pending_deploys']));
                $row->column(2, Card::make('证书部署失败', (string) $summary['failed_deploys']));
                $row->column(2, Card::make('队列积压', (string) $summary['queue_pending']));
                $row->column(2, Card::make('failed_jobs', (string) $summary['queue_failed']));
            });

            $content->row(function (Row $row) use ($issueHtml) {
                $requeueUrl = admin_url('ops/requeue-failed-deployments');
                $token = csrf_token();
                $opsHtml = $issueHtml
                    .'<form method="post" action="'.e($requeueUrl).'" class="mt-3" onsubmit="return confirm(\'将全部 failed 证书部署改回 pending？\');">'
                    .'<input type="hidden" name="_token" value="'.e($token).'">'
                    .'<input type="hidden" name="_redirect" value="'.e(admin_url('/')).'">'
                    .'<button type="submit" class="btn btn-warning btn-sm">重试全部失败部署</button>'
                    .'<span class="text-muted ml-2">等同 <code>php artisan redi:requeue-failed-deployments</code></span>'
                    .'</form>';
                $row->column(12, Card::make('运营自检（最近 7 天证书预警）', $opsHtml));
            });
        } elseif ($user) {
            $stats = TenantOpsSummary::forAdmin(AdminTenantContext::sessionAdminId());
            $tips = '<ul class="mb-0">'
                .'<li>启用域名：'.(int) $stats['domains_enabled'].'，停用：'.(int) $stats['domains_disabled'].'</li>'
                .'<li>证书已生效：'.(int) $stats['certs_active'].'</li>'
                .'<li>证书待处理（解析/签发/部署等）：'.(int) $stats['certs_needs_attention'].'</li>'
                .'<li>14 天内到期：'.(int) $stats['certs_expiring_soon'].'</li>'
                .'</ul>'
                .'<p class="text-muted mt-2 mb-0">请先完成 CNAME/A 解析，再在「域名管理」中启用域名并申请证书。</p>';

            $content->row(function (Row $row) use ($stats) {
                $row->column(3, Card::make('启用域名', (string) $stats['domains_enabled']));
                $row->column(3, Card::make('证书已生效', (string) $stats['certs_active']));
                $row->column(3, Card::make('证书待处理', (string) $stats['certs_needs_attention']));
                $row->column(3, Card::make('即将到期', (string) $stats['certs_expiring_soon']));
            });

            $content->row(function (Row $row) use ($tips) {
                $row->column(12, Card::make('我的业务概览', $tips));
            });
        }

        return $content;
    }
}
