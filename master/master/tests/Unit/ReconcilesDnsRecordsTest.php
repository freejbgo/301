<?php

namespace Tests\Unit;

use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use PHPUnit\Framework\TestCase;

class ReconcilesDnsRecordsTest extends TestCase
{
    public function test_reconcile_a_adds_before_removing_stale(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(array $existing, array $desired, array &$created, array &$deleted): void
            {
                $this->reconcileARecordValues(
                    $existing,
                    $desired,
                    function (string $ip) use (&$created) {
                        $created[] = $ip;
                    },
                    function (string $id) use (&$deleted) {
                        $deleted[] = $id;
                    }
                );
            }
        };

        $created = [];
        $deleted = [];
        $helper->run(
            [['id' => '1', 'value' => '1.2.3.4']],
            ['1.2.3.4', '5.6.7.8'],
            $created,
            $deleted
        );

        $this->assertSame(['5.6.7.8'], $created);
        $this->assertSame([], $deleted);
    }

    public function test_reconcile_a_removes_only_stale_ips(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(array $existing, array $desired, array &$deleted): void
            {
                $this->reconcileARecordValues(
                    $existing,
                    $desired,
                    function (string $ip) {
                    },
                    function (string $id) use (&$deleted) {
                        $deleted[] = $id;
                    }
                );
            }
        };

        $deleted = [];
        $helper->run(
            [
                ['id' => '1', 'value' => '1.2.3.4'],
                ['id' => '2', 'value' => '9.9.9.9'],
            ],
            ['1.2.3.4'],
            $deleted
        );

        $this->assertSame(['2'], $deleted);
    }

    public function test_reconcile_cname_updates_instead_of_delete_first(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(array $existing, string $target, array &$updated, array &$deleted): void
            {
                $this->reconcileCnameTarget(
                    $existing,
                    $target,
                    function (string $t) {
                    },
                    function (string $id, string $t) use (&$updated) {
                        $updated[] = [$id, $t];
                    },
                    function (string $id) use (&$deleted) {
                        $deleted[] = $id;
                    }
                );
            }
        };

        $updated = [];
        $deleted = [];
        $helper->run(
            [['id' => '10', 'value' => 'old.pool.example']],
            'new.pool.example',
            $updated,
            $deleted
        );

        $this->assertSame([['10', 'new.pool.example']], $updated);
        $this->assertSame([], $deleted);
    }

    public function test_delete_phase_failure_throws_aggregate(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(): void
            {
                $this->reconcileARecordValues(
                    [['id' => '1', 'value' => '9.9.9.9']],
                    ['1.2.3.4'],
                    function (string $ip) {
                    },
                    function (string $id) {
                        throw new \RuntimeException('boom');
                    }
                );
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNS 删除陈旧记录失败');
        $helper->run();
    }
}
