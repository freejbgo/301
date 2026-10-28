<?php

namespace Tests\Unit;

use App\Support\AdminBatchOperationAudit;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AdminBatchOperationAuditTest extends TestCase
{
    public function test_record_writes_structured_admin_batch_log(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('admin_batch')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->with('admin_batch', \Mockery::on(function (array $payload): bool {
                return $payload['event'] === 'admin_batch'
                    && $payload['module'] === 'domain'
                    && $payload['action'] === 'batch_enable'
                    && $payload['admin_id'] === 42
                    && $payload['updated'] === 3
                    && $payload['selection']['id_count'] === 2;
            }));

        AdminBatchOperationAudit::record('domain', 'batch_enable', 42, [
            'updated' => 3,
            'skipped' => 0,
            'truncated' => 0,
            'error_count' => 0,
        ], [
            'selection' => AdminBatchOperationAudit::idSample([5, 9, 9]),
        ]);
    }
}
