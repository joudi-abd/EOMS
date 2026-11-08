<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Mirrors deptrac.yaml so a boundary break fails the normal test run even where deptrac is not installed. */
class ModuleBoundariesTest extends TestCase
{
    private const ALLOWED = ['Identity' => [], 'Settings' => [], 'HR' => ['Identity'], 'Payroll' => ['HR', 'Identity', 'Settings']];

    public function test_modules_only_depend_on_what_deptrac_allows(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2).'/app/Modules';
        foreach (self::ALLOWED as $module => $allowed) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$module));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all('/App\\\\Modules\\\\(\w+)\\\\/', file_get_contents($file->getPathname()), $matches);
                foreach (array_unique($matches[1]) as $used) {
                    if ($used !== $module && ! in_array($used, $allowed, true)) {
                        $violations[] = "{$module} → {$used}: ".substr($file->getPathname(), strlen($root) + 1);
                    }
                }
            }
        }
        $this->assertSame([], $violations);
    }
}
