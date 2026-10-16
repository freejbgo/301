<?php

namespace App\Services\Dns\Concerns;

use App\Models\RediNode;
use App\Support\HostNameValidator;
use App\Support\OpsDisplaySanitizer;

/**
 * 先增/改、后删，避免「先删后建」在 API 失败时把记录删光。
 *
 * @phpstan-type DnsRow array{id: string, value: string}
 */
trait ReconcilesDnsRecords
{
    /**
     * @param  DnsRow[]  $existing
     * @param  string[]  $desiredIps
     */
    protected function reconcileARecordValues(array $existing, array $desiredIps, callable $create, callable $deleteById): void
    {
        $desired = [];
        foreach (RediNode::filterValidIpv4List($desiredIps) as $ip) {
            $desired[strtolower($ip)] = $ip;
        }
        if ($desired === []) {
            throw new \RuntimeException('没有可同步的节点 IP');
        }

        $kept = [];
        $toDelete = [];
        foreach ($existing as $row) {
            $id = (string) ($row['id'] ?? '');
            $value = strtolower(rtrim(trim((string) ($row['value'] ?? '')), '.'));
            if ($id === '' || $value === '') {
                continue;
            }
            if (isset($desired[$value]) && ! isset($kept[$value])) {
                $kept[$value] = $id;
            } else {
                $toDelete[] = $id;
            }
        }

        foreach ($desired as $lower => $ip) {
            if (! isset($kept[$lower])) {
                $create($ip);
            }
        }

        $this->deleteDnsRecordIds($toDelete, $deleteById);
    }

    /**
     * @param  DnsRow[]  $existing
     */
    protected function reconcileCnameTarget(array $existing, string $targetFqdn, callable $create, callable $update, callable $deleteById): void
    {
        $target = HostNameValidator::toCanonicalAscii($targetFqdn);
        if ($target === '' || ! HostNameValidator::isValidHostLabel($target)) {
            throw new \RuntimeException('CNAME 目标 无效');
        }
        $target = strtolower(rtrim($target, '.'));

        $keeperId = null;
        $toDelete = [];
        foreach ($existing as $row) {
            $id = (string) ($row['id'] ?? '');
            $value = strtolower(rtrim(trim((string) ($row['value'] ?? '')), '.'));
            if ($id === '') {
                continue;
            }
            if ($value === $target && $keeperId === null) {
                $keeperId = $id;
            } else {
                $toDelete[] = $id;
            }
        }

        if ($keeperId === null) {
            if ($existing !== []) {
                $firstId = (string) ($existing[0]['id'] ?? '');
                if ($firstId !== '') {
                    $update($firstId, $target);
                    $keeperId = $firstId;
                    for ($i = 1; $i < count($existing); $i++) {
                        $id = (string) ($existing[$i]['id'] ?? '');
                        if ($id !== '') {
                            $toDelete[] = $id;
                        }
                    }
                } else {
                    $create($target);
                }
            } else {
                $create($target);
            }
        }

        $ids = [];
        foreach (array_unique($toDelete) as $id) {
            if ($keeperId !== null && $id === $keeperId) {
                continue;
            }
            $ids[] = $id;
        }
        $this->deleteDnsRecordIds($ids, $deleteById);
    }

    /**
     * @param  string[]  $ids
     */
    protected function deleteDnsRecordIds(array $ids, callable $deleteById): void
    {
        $failed = [];
        foreach ($ids as $id) {
            try {
                $deleteById($id);
            } catch (\Throwable $e) {
                $failed[] = $id.': '.OpsDisplaySanitizer::logSnippet($e->getMessage(), 200);
            }
        }
        if ($failed !== []) {
            throw new \RuntimeException('DNS 删除陈旧记录失败: '.implode('; ', $failed));
        }
    }
}
