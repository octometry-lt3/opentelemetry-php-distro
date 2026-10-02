<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OTelDistroTests\Util\TestCaseBase;

final class FixComposerAutoloadOrderTest extends TestCaseBase
{
    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function autoloadFixtures(): iterable
    {
        foreach ([false, true] as $compact) {
            foreach ([false, true] as $trailingComma) {
                yield ($compact ? 'compact' : 'multiline') . '_' . ($trailingComma ? 'trailing' : 'no_trailing') => [$compact, $trailingComma];
            }
        }
    }

    /**
     * Covers Composer's multiline output and php-scoper's compact output, including the final
     * entry without a comma that caused the gRPC repair to report missing entry bounds.
     *
     * @dataProvider autoloadFixtures
     */
    public function testReordersFinalGrpcEntryWithoutDamagingEitherAutoloadArray(bool $compact, bool $trailingComma): void
    {
        $root = sys_get_temp_dir() . '/otel-autoload-order-' . bin2hex(random_bytes(8));
        mkdir($root . '/composer', 0777, true);
        try {
            $autoloadFiles = $this->autoloadFilesFixture($compact, $trailingComma);
            $autoloadStatic = $this->autoloadStaticFixture($compact, $trailingComma);
            file_put_contents($root . '/composer/autoload_files.php', $autoloadFiles);
            file_put_contents($root . '/composer/autoload_static.php', $autoloadStatic);

            $script = dirname(__DIR__, 3) . '/tools/build/fix_composer_autoload_order.php';
            $this->runPhp($script, [$root]);
            $filesAfterFirstRun = [
                file_get_contents($root . '/composer/autoload_files.php'),
                file_get_contents($root . '/composer/autoload_static.php'),
            ];

            foreach ($filesAfterFirstRun as $content) {
                self::assertIsString($content);
                self::assertSame(1, substr_count($content, '/open-telemetry/exporter-otlp/_register.php'));
                self::assertSame(1, substr_count($content, '/open-telemetry/transport-grpc/_register.php'));
                self::assertSame(1, substr_count($content, '/open-telemetry/sdk/_autoload.php'));
                self::assertSame(1, substr_count($content, 'earlySetup.php'));
                self::assertLessThan(strpos($content, '/open-telemetry/exporter-otlp/_register.php'), strpos($content, 'earlySetup.php'));
                self::assertLessThan(strpos($content, '/open-telemetry/transport-grpc/_register.php'), strpos($content, '/open-telemetry/exporter-otlp/_register.php'));
                self::assertLessThan(strpos($content, '/open-telemetry/sdk/_autoload.php'), strpos($content, '/open-telemetry/transport-grpc/_register.php'));
                self::assertStringContainsString("'unrelated' => ['keep', 'these', 'commas']", $content);
                $this->runPhp('-l', [], $content);
            }

            $this->runPhp($script, [$root]);
            self::assertSame($filesAfterFirstRun[0], file_get_contents($root . '/composer/autoload_files.php'));
            self::assertSame($filesAfterFirstRun[1], file_get_contents($root . '/composer/autoload_static.php'));
        } finally {
            @unlink($root . '/composer/autoload_files.php');
            @unlink($root . '/composer/autoload_static.php');
            @rmdir($root . '/composer');
            @rmdir($root);
        }
    }

    private function autoloadFilesFixture(bool $compact, bool $trailingComma): string
    {
        $entries = [
            "'sdk' => \$vendorDir . '/open-telemetry/sdk/_autoload.php',",
            "'exporter' => \$vendorDir . '/open-telemetry/exporter-otlp/_register.php',",
            "'grpc' => \$vendorDir . '/open-telemetry/transport-grpc/_register.php'",
        ];
        if ($trailingComma) {
            $entries[2] .= ',';
        }
        return "<?php\nreturn array (" . implode($compact ? '' : "\n", $entries) . ");\n\$unrelated = ['unrelated' => ['keep', 'these', 'commas']];\n";
    }

    private function autoloadStaticFixture(bool $compact, bool $trailingComma): string
    {
        $entries = [
            "'sdk' => __DIR__ . '/../../open-telemetry/sdk/_autoload.php',",
            "'exporter' => __DIR__ . '/../../open-telemetry/exporter-otlp/_register.php',",
            "'grpc' => __DIR__ . '/../../open-telemetry/transport-grpc/_register.php'",
        ];
        if ($trailingComma) {
            $entries[2] .= ',';
        }
        $files = implode($compact ? '' : "\n", $entries);
        return "<?php\nclass ComposerStaticInit { public static \$files = array ($files); "
            . "public static \$unrelated = ['unrelated' => ['keep', 'these', 'commas']]; }\n";
    }

    /** @param list<string> $arguments */
    private function runPhp(string $script, array $arguments, ?string $stdin = null): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);
    }
}
