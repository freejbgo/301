<?php

namespace Tests\Unit;

use App\Services\Dns\Concerns\ReconcilesDnsRecords;
use App\Support\RediErrorMessage;
use Tests\TestCase;

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

    public function test_reconcile_cname_rejects_invalid_target(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(string $target): void
            {
                $this->reconcileCnameTarget(
                    [],
                    $target,
                    function (string $t) {
                    },
                    function (string $id, string $t) {
                    },
                    function (string $id) {
                    }
                );
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(RediErrorMessage::get('dns.cname_target_invalid'));
        $helper->run('not..valid');
    }

    public function test_reconcile_a_ignores_invalid_ipv4_in_desired(): void
    {
        $helper = new class
        {
            use ReconcilesDnsRecords;

            public function run(array &$created): void
            {
                $this->reconcileARecordValues(
                    [],
                    ['not-ip', '5.6.7.8'],
                    function (string $ip) use (&$created) {
                        $created[] = $ip;
                    },
                    function (string $id) {
                    }
                );
            }
        };

        $created = [];
        $helper->run($created);
        $this->assertSame(['5.6.7.8'], $created);
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
        $this->expectExceptionMessage('Failed to delete stale DNS records');
        $helper->run();
    }

    public function test_reconcile_aaaa_empty_desired_deletes_existing(): void
    {
        $helper = $this->aaaaHelper();
        $created = [];
        $deleted = [];
        $helper->run(
            [['id' => '1', 'value' => '2001:db8::1']],
            [],
            $created,
            $deleted
        );
        $this->assertSame([], $created);
        $this->assertSame(['1'], $deleted);
    }

    public function test_reconcile_aaaa_whitespace_desired_deletes_existing(): void
    {
        $helper = $this->aaaaHelper();
        $deleted = [];
        $created = [];
        $helper->run(
            [['id' => '2', 'value' => '2001:db8::2']],
            ['  '],
            $created,
            $deleted
        );
        $this->assertSame([], $created);
        $this->assertSame(['2'], $deleted);
    }

    public function test_reconcile_aaaa_invalid_desired_throws_before_delete(): void
    {
        $helper = $this->aaaaHelper();
        $deleted = [];
        $created = [];
        try {
            $helper->run(
                [['id' => '3', 'value' => '2001:db8::3']],
                ['not-an-ip'],
                $created,
                $deleted
            );
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not-an-ip', $e->getMessage());
        }
        $this->assertSame([], $created);
        $this->assertSame([], $deleted);
    }

    public function test_reconcile_aaaa_removes_invalid_existing_when_desired_valid(): void
    {
        $helper = $this->aaaaHelper();
        $deleted = [];
        $created = [];
        $helper->run(
            [['id' => '4', 'value' => 'fe80::1']],
            ['2001:db8::1'],
            $created,
            $deleted
        );
        $this->assertSame(['2001:db8::1'], $created);
        $this->assertSame(['4'], $deleted);
    }

    public function test_reconcile_aaaa_canonical_match_skips_create_and_delete(): void
    {
        $helper = $this->aaaaHelper();
        $deleted = [];
        $created = [];
        $helper->run(
            [['id' => '5', 'value' => '2001:0db8::1']],
            ['2001:db8::1'],
            $created,
            $deleted
        );
        $this->assertSame([], $created);
        $this->assertSame([], $deleted);
    }

    public function test_reconcile_aaaa_creates_before_deleting_stale(): void
    {
        $helper = $this->aaaaHelper();
        $created = [];
        $deleted = [];
        $order = [];
        $helper->run(
            [
                ['id' => '6', 'value' => '2001:db8::1'],
                ['id' => '7', 'value' => '2001:db8::9'],
            ],
            ['2001:db8::1', '2001:db8::8'],
            $created,
            $deleted,
            $order
        );
        $this->assertSame(['2001:db8::8'], $created);
        $this->assertSame(['7'], $deleted);
        $this->assertSame(['create', 'delete'], $order);
    }

    /**
     * @return object{run: callable}
     */
    private function aaaaHelper(): object
    {
        return new class
        {
            use ReconcilesDnsRecords;

            /**
             * @param  array<int, array{id: string, value: string}>  $existing
             * @param  string[]  $desired
             * @param  string[]  $created
             * @param  string[]  $deleted
             * @param  string[]|null  $order
             */
            public function run(array $existing, array $desired, array &$created, array &$deleted, ?array &$order = null): void
            {
                $this->reconcileAAAARecordValues(
                    $existing,
                    $desired,
                    function (string $ip) use (&$created, &$order) {
                        $created[] = $ip;
                        if ($order !== null) {
                            $order[] = 'create';
                        }
                    },
                    function (string $id) use (&$deleted, &$order) {
                        $deleted[] = $id;
                        if ($order !== null) {
                            $order[] = 'delete';
                        }
                    }
                );
            }
        };
    }
}
