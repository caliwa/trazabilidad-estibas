<?php

namespace Tests\Feature\Docs;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * docs/informe-cumplimiento.md maps every requirement to tests. This keeps
 * the map honest: a renamed or deleted test breaks the build.
 */
class ComplianceReportTest extends TestCase
{
    public function test_every_test_cited_in_the_report_exists(): void
    {
        $report = (string) file_get_contents(base_path('docs/informe-cumplimiento.md'));
        preg_match_all('/`([A-Z][A-Za-z0-9]+Test)::(test_[a-z0-9_]+)`/', $report, $matches, PREG_SET_ORDER);

        $this->assertGreaterThan(100, count($matches), 'El informe casi no cita pruebas');

        $classes = [];
        foreach ((new Finder)->files()->in(base_path('tests'))->name('*Test.php') as $file) {
            preg_match('/^namespace ([^;]+);/m', $file->getContents(), $ns);
            $classes[$file->getBasename('.php')] = $ns[1].'\\'.$file->getBasename('.php');
        }

        $missing = [];
        foreach ($matches as [, $class, $method]) {
            if (! isset($classes[$class]) || ! method_exists($classes[$class], $method)) {
                $missing[] = "{$class}::{$method}";
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'El informe cita pruebas que no existen');
    }
}
