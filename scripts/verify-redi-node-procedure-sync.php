<?php

declare(strict_types=1);

/**
 * 确保 db/redi_admin.sql 与迁移 100009 中的 redi_node_bump_skip 过程体一致。
 */
$root = dirname(__DIR__);
$sqlDump = file_get_contents($root.'/db/redi_admin.sql');
$migration = file_get_contents($root.'/master/master/database/migrations/2026_03_28_100009_redi_node_skip_procedure.php');

if (! preg_match('/CREATE PROCEDURE `redi_node_bump_skip`.*?END\s*;;/ms', $sqlDump, $fromSql)) {
    fwrite(STDERR, "redi_admin.sql: missing CREATE PROCEDURE redi_node_bump_skip\n");
    exit(1);
}

if (! preg_match_all("/DB::unprepared\(\s*(.*?)\s*\);/s", $migration, $blocks)) {
    fwrite(STDERR, "migration 100009: missing DB::unprepared SQL\n");
    exit(1);
}

$fromMig = '';
foreach ($blocks[1] as $block) {
    if (! str_contains($block, 'CREATE PROCEDURE')) {
        continue;
    }
    if (! preg_match_all("/'((?:\\\\'|[^'])*)'/", $block, $chunks)) {
        fwrite(STDERR, "migration 100009: could not parse SQL fragments\n");
        exit(1);
    }
    foreach ($chunks[1] as $part) {
        $fromMig .= str_replace("\\'", "'", $part);
    }
    break;
}
if ($fromMig === '') {
    fwrite(STDERR, "migration 100009: CREATE PROCEDURE block not found\n");
    exit(1);
}

$normalize = static function (string $body): string {
    $body = preg_replace('/\s+/', ' ', $body) ?? $body;

    return strtolower(trim($body));
};

$fromSqlBody = preg_replace('/\s*;;\s*$/', '', $fromSql[0]) ?? $fromSql[0];

if ($normalize($fromSqlBody) !== $normalize($fromMig)) {
    fwrite(STDERR, "redi_node_bump_skip body mismatch between redi_admin.sql and migration 100009\n");
    exit(1);
}

fwrite(STDOUT, "redi_node_bump_skip: SQL dump and migration 100009 match\n");
