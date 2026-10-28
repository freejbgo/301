<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured audit trail for admin grid / filter batch operations (file log).
 */
class AdminBatchOperationAudit
{
    public const ID_SAMPLE_MAX = 25;

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $context
     */
    public static function record(string $module, string $action, int $adminId, array $metrics, array $context = []): void
    {
        if ($adminId <= 0) {
            return;
        }

        $payload = array_merge(
            [
                'event' => 'admin_batch',
                'module' => $module,
                'action' => $action,
                'admin_id' => $adminId,
                'ip' => request()?->ip(),
                'at' => now()->toIso8601String(),
            ],
            self::sanitizeMetrics($metrics),
            self::sanitizeContext($context)
        );

        Log::channel('admin_batch')->info('admin_batch', $payload);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{id_count: int, id_sample: list<int>, id_sample_truncated: int}
     */
    public static function idSample(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids);
        $total = count($ids);
        $sample = array_slice($ids, 0, self::ID_SAMPLE_MAX);

        return [
            'id_count' => $total,
            'id_sample' => $sample,
            'id_sample_truncated' => max(0, $total - count($sample)),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    public static function metricsFromMutator(array $result): array
    {
        $metrics = [
            'updated' => (int) ($result['updated'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'truncated' => (int) ($result['truncated'] ?? 0),
            'error_count' => count($result['errors'] ?? []),
        ];

        if (isset($result['deployments_requeued'])) {
            $metrics['deployments_requeued'] = (int) $result['deployments_requeued'];
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    protected static function sanitizeMetrics(array $metrics): array
    {
        unset($metrics['tokens'], $metrics['token']);

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected static function sanitizeContext(array $context): array
    {
        unset($context['tokens'], $context['token'], $context['api_token']);

        return $context;
    }
}
