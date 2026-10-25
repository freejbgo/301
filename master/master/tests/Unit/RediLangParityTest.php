<?php

namespace Tests\Unit;

use Tests\TestCase;

class RediLangParityTest extends TestCase
{
    public function test_zh_tw_and_en_redi_lang_keys_match_zh_cn(): void
    {
        $this->assertLangFilesParity('redi-*.php');
    }

    public function test_zh_tw_and_en_dcat_shell_lang_keys_match_zh_cn(): void
    {
        foreach (['menu.php', 'global.php', 'extension.php'] as $name) {
            $this->assertLangFileParity($name);
        }
    }

    protected function assertLangFilesParity(string $pattern): void
    {
        $baseDir = resource_path('lang');
        $files = glob($baseDir.'/zh_CN/'.$pattern) ?: [];

        $this->assertNotEmpty($files, "expected zh_CN {$pattern} language files");

        foreach ($files as $zhPath) {
            $this->assertLangFileParity(basename($zhPath));
        }
    }

    protected function assertLangFileParity(string $name): void
    {
        $baseDir = resource_path('lang');
        $zhPath = $baseDir.'/zh_CN/'.$name;
        $this->assertFileExists($baseDir.'/en/'.$name, "missing en/{$name}");
        $this->assertFileExists($baseDir.'/zh_TW/'.$name, "missing zh_TW/{$name}");

        $zhKeys = $this->flattenKeys(include $zhPath);
        $enKeys = $this->flattenKeys(include $baseDir.'/en/'.$name);
        $twKeys = $this->flattenKeys(include $baseDir.'/zh_TW/'.$name);

        $this->assertSame($zhKeys, $enKeys, "en/{$name} keys mismatch");
        $this->assertSame($zhKeys, $twKeys, "zh_TW/{$name} keys mismatch");
    }

    /**
     * @param  array<string, mixed>  $array
     * @return list<string>
     */
    protected function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }
        sort($keys);

        return $keys;
    }
}
