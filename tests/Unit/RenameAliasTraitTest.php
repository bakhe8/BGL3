<?php

use PHPUnit\Framework\TestCase;

/**
 * @group fast
 */
class RenameAliasTraitTest extends TestCase
{
    public function testStringAndTraitAliasesAreRenamed(): void
    {
        $file = __DIR__ . '/tmp_alias_trait.php';
        $code = <<<'PHP'
<?php
namespace Demo;

use Foo\Bar as OldAlias;

class MyClass {
    use OldAlias;

    public function check(): bool {
        return class_exists('Foo\Bar');
    }
}
PHP;
        file_put_contents($file, $code);

        $params = json_encode(['old_name' => 'Foo\Bar', 'new_name' => 'Baz\\Qux']);
        $vendor = __DIR__ . '/../../vendor';

        // أنشئ رانر PHP مؤقت يمرر argv ويشغّل patcher مباشرة (يتجنب مشاكل الاقتباس في Windows)
        $runner = tempnam(sys_get_temp_dir(), 'bgl_runner_') . '.php';
        $runnerCode = "<?php\n"
            . '$argv=[0,' . var_export($file, true) . ','
            . var_export('rename_reference', true) . ','
            . var_export($params, true) . ','
            . var_export($vendor, true) . "];\n"
            . '$argc=5;' . "\n"
            . 'include ' . var_export(__DIR__ . "/../../.bgl_core/actuators/patcher.php", true) . ";\n";
        file_put_contents($runner, $runnerCode);

        $raw = shell_exec("php " . escapeshellarg($runner));
        $result = json_decode($raw, true);

        if (($result['status'] ?? '') !== 'success') {
            $this->fail("Patcher returned error: " . ($raw ?: 'no output'));
        }
        @unlink($runner);

        $this->assertEquals('success', $result['status'] ?? null);
        $updated = file_get_contents($file);
        $this->assertStringContainsString('use Baz\\Qux as OldAlias;', $updated);
        $this->assertStringContainsString('use Baz\\Qux;', str_replace('OldAlias', 'Baz\\Qux', $updated));
        $this->assertStringContainsString("class_exists('Baz\\Qux')", $updated);

        unlink($file);
    }
}
