<?php

namespace App\Console\Commands;

use App\Services\CertificateDeployService;
use Illuminate\Console\Command;

class RequeueFailedDeploymentsCommand extends Command
{
    protected $signature = 'redi:requeue-failed-deployments
                            {--node= : 仅重试指定节点 ID}
                            {--cert= : 仅重试指定证书 ID}';

    protected $description = '将全部 failed 证书部署行改回 pending（可限定节点或证书）';

    public function handle(CertificateDeployService $deployService): int
    {
        $nodeId = $this->option('node');
        $certId = $this->option('cert');

        $count = $deployService->requeueFailedDeployments(
            $nodeId !== null && $nodeId !== '' ? (int) $nodeId : null,
            $certId !== null && $certId !== '' ? (int) $certId : null
        );

        if ($count === 0) {
            $this->info('没有 failed 部署记录需要重试。');

            return self::SUCCESS;
        }

        $this->info("已重新排队 {$count} 条 failed 部署。");

        return self::SUCCESS;
    }
}
