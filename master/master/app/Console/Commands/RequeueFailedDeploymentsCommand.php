<?php

namespace App\Console\Commands;

use App\Services\CertificateDeployService;
use App\Support\RediErrorMessage;
use Illuminate\Console\Command;

class RequeueFailedDeploymentsCommand extends Command
{
    protected $signature = 'redi:requeue-failed-deployments
                            {--node= : Only retry deployments for this node ID}
                            {--cert= : Only retry deployments for this certificate ID}';

    protected $description = 'Requeue all failed certificate deployments as pending (optional node or cert filter)';

    public function handle(CertificateDeployService $deployService): int
    {
        $nodeId = $this->option('node');
        $certId = $this->option('cert');

        $nodeFilter = null;
        if ($nodeId !== null && $nodeId !== '') {
            $nodeFilter = (int) $nodeId;
            if ($nodeFilter < 1) {
                $this->error(RediErrorMessage::get('cli.requeue_node_id'));

                return self::FAILURE;
            }
        }

        $certFilter = null;
        if ($certId !== null && $certId !== '') {
            $certFilter = (int) $certId;
            if ($certFilter < 1) {
                $this->error(RediErrorMessage::get('cli.requeue_cert_id'));

                return self::FAILURE;
            }
        }

        $count = $deployService->requeueFailedDeployments($nodeFilter, $certFilter);

        if ($count === 0) {
            $this->info(RediErrorMessage::get('cli.requeue_none'));

            return self::SUCCESS;
        }

        $this->info(RediErrorMessage::get('cli.requeue_done', ['count' => $count]));

        return self::SUCCESS;
    }
}
