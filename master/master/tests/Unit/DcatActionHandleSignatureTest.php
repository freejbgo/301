<?php

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

class DcatActionHandleSignatureTest extends TestCase
{
    public function test_dcat_actions_accept_only_the_request(): void
    {
        $root = app_path('Admin/Actions');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $checked = 0;

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! preg_match('/namespace\s+([^;]+);/', $contents, $namespace)
                || ! preg_match('/class\s+(\w+)/', $contents, $class)) {
                continue;
            }

            $fqcn = $namespace[1].'\\'.$class[1];
            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if ($reflection->isAbstract() || ! $reflection->hasMethod('handle')) {
                continue;
            }

            $method = $reflection->getMethod('handle');
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            $this->assertSame(
                1,
                $method->getNumberOfRequiredParameters(),
                $fqcn.'::handle is called with only the request'
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }
}
