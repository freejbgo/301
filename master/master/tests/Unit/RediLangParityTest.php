<?php

namespace Tests\Unit;

use Tests\TestCase;

class RediLangParityTest extends TestCase
{
    public function test_zh_tw_and_en_redi_lang_keys_match_zh_cn(): void
    {
        $baseDir = resource_path('lang');
        $files = glob($baseDir.'/zh_CN/redi-*.php') ?: [];

        $this->assertNotEmpty($files, 'expected zh_CN redi-* language files');

        foreach ($files as $zhPath) {
            $name = basename($zhPath);
            $zhKeys = $this->flattenKeys(include $zhPath);
            $enKeys = $this->flattenKeys(include $baseDir.'/en/'.$name);
            $twKeys = $this->flattenKeys(include $baseDir.'/zh_TW/'.$name);

            $this->assertSame($zhKeys, $enKeys, "en/{$name} keys mismatch");
            $this->assertSame($zhKeys, $twKeys, "zh_TW/{$name} keys mismatch");
        }
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
