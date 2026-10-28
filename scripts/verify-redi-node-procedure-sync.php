<?php

declare(strict_types=1);

/**
 * 确保 db/redi_admin.sql 与迁移 100009 / 100011 中的 redi_node_bump_skip 过程体一致。
 */
$root = dirname(__DIR__);
$sqlDump = file_get_contents($root.'/db/redi_admin.sql');

$normalize = static function (string $body): string {
    $body = preg_replace('/\s+/', ' ', $body) ?? $body;

    return strtolower(trim($body));
};

$extractFromMigration = static function (string $migrationPath) use ($normalize): string {
    $migration = file_get_contents($migrationPath);
    if (! preg_match_all("/DB::unprepared\(\s*(.*?)\s*\);/s", $migration, $blocks)) {
        fwrite(STDERR, "{$migrationPath}: missing DB::unprepared SQL\n");
        exit(1);
    }
    $fromMig = '';
    foreach ($blocks[1] as $block) {
        if (! str_contains($block, 'CREATE PROCEDURE')) {
            continue;
        }
        if (! preg_match_all("/'((?:\\\\'|[^'])*)'/", $block, $chunks)) {
            fwrite(STDERR, "{$migrationPath}: could not parse SQL fragments\n");
            exit(1);
        }
        foreach ($chunks[1] as $part) {
            $fromMig .= str_replace("\\'", "'", $part);
        }
        break;
    }
    if ($fromMig === '') {
        fwrite(STDERR, "{$migrationPath}: CREATE PROCEDURE block not found\n");
        exit(1);
    }

    return $normalize($fromMig);
};

if (! preg_match('/CREATE PROCEDURE `redi_node_bump_skip`.*?END\s*;;/ms', $sqlDump, $fromSql)) {
    fwrite(STDERR, "redi_admin.sql: missing CREATE PROCEDURE redi_node_bump_skip\n");
    exit(1);
}

$fromSqlBody = preg_replace('/\s*;;\s*$/', '', $fromSql[0]) ?? $fromSql[0];
$sqlNorm = $normalize($fromSqlBody);

$mig009 = $root.'/master/master/database/migrations/2026_03_28_100009_redi_node_skip_procedure.php';
$mig011 = $root.'/master/master/database/migrations/2026_10_29_100011_redi_node_skip_bump_guard.php';

foreach ([$mig009 => '100009', $mig011 => '100011'] as $path => $label) {
    if (! is_file($path)) {
        fwrite(STDERR, "missing migration {$label}\n");
        exit(1);
    }
    if ($sqlNorm !== $extractFromMigration($path)) {
        fwrite(STDERR, "redi_node_bump_skip body mismatch between redi_admin.sql and migration {$label}\n");
        exit(1);
    }
}

if (! preg_match('/CREATE TABLE `users`/m', $sqlDump)) {
    fwrite(STDERR, "redi_admin.sql: missing CREATE TABLE users (Laravel default migration)\n");
    exit(1);
}

$migrationFiles = glob($root.'/master/master/database/migrations/*.php') ?: [];
$migrationCount = count($migrationFiles);
if (! preg_match('/INSERT INTO `migrations` VALUES /', $sqlDump)) {
    fwrite(STDERR, "redi_admin.sql: missing migrations seed\n");
    exit(1);
}
preg_match_all("/'[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[^']+'/", $sqlDump, $seedNames);
$sqlMigrationCount = count(array_unique($seedNames[0] ?? []));
if ($sqlMigrationCount !== $migrationCount) {
    fwrite(STDERR, "redi_admin.sql: migrations seed count {$sqlMigrationCount} != filesystem {$migrationCount}\n");
    exit(1);
}

if (! preg_match('/DROP VIEW IF EXISTS `redi_node_domain_lookup`/m', $sqlDump)) {
    fwrite(STDERR, "redi_admin.sql: expected DROP VIEW redi_node_domain_lookup (greenfield uses procedure only)\n");
    exit(1);
}

fwrite(STDOUT, "redi_node_bump_skip: SQL dump matches migrations 100009 and 100011\n");
fwrite(STDOUT, "redi_admin.sql: users table present\n");
fwrite(STDOUT, "redi_admin.sql: {$migrationCount} migrations in seed\n");
