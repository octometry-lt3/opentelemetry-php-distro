<?php

/**
 * Self-contained regression runner for the scoped OTLP/gRPC descriptor and response path.
 * It consumes already-built fixtures without Composer installation and only changes a temporary copy.
 */

// phpcs:ignoreFile PSR1.Files.SideEffects

declare(strict_types=1);

const PREFIX = 'OTelDistroScoped';

function fail(string $message): never
{
    throw new RuntimeException($message);
}

function copyTree(string $source, string $target): void
{
    if (!is_dir($source) && !is_file($source)) {
        fail("Fixture path does not exist: $source");
    }
    if (is_file($source)) {
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
            fail("Unable to create fixture directory: " . dirname($target));
        }
        if (!copy($source, $target)) {
            fail("Unable to copy fixture file: $source");
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;
        if ($file->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                fail("Unable to create fixture directory: $destination");
            }
        } elseif (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0777, true) && !is_dir(dirname($destination))) {
            fail("Unable to create fixture directory: " . dirname($destination));
        } elseif (!copy($file->getPathname(), $destination)) {
            fail("Unable to copy fixture file: " . $file->getPathname());
        }
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function registerScopedAutoloader(string $vendor): void
{
    spl_autoload_register(static function (string $class) use ($vendor): void {
        $prefixes = [
            PREFIX . '\\Google\\Protobuf\\' => $vendor . '/google/protobuf/src/Google/Protobuf/',
            PREFIX . '\\GPBMetadata\\Google\\Protobuf\\' => $vendor . '/google/protobuf/src/GPBMetadata/Google/Protobuf/',
            PREFIX . '\\GPBMetadata\\' => $vendor . '/open-telemetry/gen-otlp-protobuf/GPBMetadata/',
            PREFIX . '\\Opentelemetry\\Proto\\' => $vendor . '/open-telemetry/gen-otlp-protobuf/Opentelemetry/Proto/',
        ];
        foreach ($prefixes as $prefix => $base) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
            return;
        }
    });
}

function runPatcherSourceCases(): void
{
    $config = require __DIR__ . '/../build/php-scoper.inc.php';
    /** @var list<Closure> $patchers */
    $patcher = $config['patchers'][1];
    $path = '/fixture/google/protobuf/src/Google/Protobuf/Internal/GPBUtil.php';
    $source = static fn(string $branches): string => implode("\n", [
        '<?php',
        'namespace ' . PREFIX . '\\Google\\Protobuf\\Internal;',
        'final class GPBUtil {',
        '    public static function getFullClassName(): void {',
        '        $option = null;',
        "        \$package = 'opentelemetry.proto';",
        $branches,
        '        $classname = null;',
        '    }',
        '    public static function combineInt32ToInt64(): void {}',
        '}',
        '',
    ]);
    $branchForms = [
        'unqualified' => "        if (!is_null(\$option) && \$option->hasPhpNamespace()) {\n        }",
        'qualified' => "        if (!\\is_null(\$option) && \$option->hasPhpNamespace()) {\n        }",
        'whitespace' => "        if ( ! is_null( \$option ) && \$option->hasPhpNamespace( ) ) {\n        }",
        'crlf' => "        if (!is_null(\$option) && \$option->hasPhpNamespace()) {\r\n        }",
    ];
    $exactMarker = "        if (!\\is_null(\$option) && \$option->hasPhpNamespace()) {";
    if (substr_count($branchForms['unqualified'], $exactMarker) !== 0 || substr_count($branchForms['qualified'], $exactMarker) !== 1) {
        fail('The regression fixture does not prove the previous exact matcher distinction.');
    }
    foreach ($branchForms as $name => $branch) {
        $original = $source($branch);
        $patched = $patcher($path, PREFIX, $original);
        if (!str_contains($patched, $branch)) {
            fail("Patcher source case '$name' did not retain the matched branch.");
        }
        if (!str_contains($patched, 'OTEL scoped protobuf descriptor class fix') || $patcher($path, PREFIX, $patched) !== $patched) {
            fail("Patcher source case '$name' was not patched idempotently.");
        }
        $process = proc_open([PHP_BINARY, '-l'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            fail("Unable to start PHP lint for '$name'.");
        }
        fwrite($pipes[0], $patched);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            fail("Patched source case '$name' is not valid PHP:\n" . $output);
        }
        echo "patcher source case passed: $name\n";
    }
    $invalid = [
        'missing' => $source(''),
        'duplicate' => $source($branchForms['qualified'] . "\n" . $branchForms['unqualified']),
    ];
    foreach ($invalid as $name => $content) {
        try {
            $patcher($path, PREFIX, $content);
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'expected exactly one php_namespace branch')) {
                echo "patcher source case passed: $name failure\n";
                continue;
            }
            throw $exception;
        }
        fail("Patcher source case '$name' was accepted.");
    }
}

function responseClass(): string
{
    return PREFIX . '\\Opentelemetry\\Proto\\Collector\\Trace\\V1\\ExportTraceServiceResponse';
}

function partialSuccessClass(): string
{
    return PREFIX . '\\Opentelemetry\\Proto\\Collector\\Trace\\V1\\ExportTracePartialSuccess';
}

function assertDescriptorNames(): void
{
    $option = new class {
        public function hasPhpNamespace(): bool
        {
            return false;
        }

        public function getPhpClassPrefix(): string
        {
            return '';
        }
    };
    $file = new class ($option) {
        public function __construct(private readonly object $option)
        {
        }

        public function getPackage(): string
        {
            return 'opentelemetry.proto.collector.trace.v1';
        }

        public function getOptions(): object
        {
            return $this->option;
        }
    };
    $message = new class {
        public function getName(): string
        {
            return 'ExportTraceServiceResponse';
        }
    };
    $classname = $legacy = $fullname = $previous = null;
    $unused = '';
    $gpbUtil = PREFIX . '\\Google\\Protobuf\\Internal\\GPBUtil';
    $gpbUtil::getFullClassName($message, '', $file, $unused, $classname, $legacy, $fullname, $previous);
    $expected = PREFIX . '\\Opentelemetry\\Proto\\Collector\\Trace\\V1\\ExportTraceServiceResponse';
    if ($classname !== $expected || $legacy !== $expected || $previous !== $expected) {
        fail('Scoped descriptor class names were not prefixed as expected.');
    }

    $unscopedFile = new class ($option) {
        public function __construct(private readonly object $option)
        {
        }

        public function getPackage(): string
        {
            return 'example.messages';
        }

        public function getOptions(): object
        {
            return $this->option;
        }
    };
    $gpbUtil::getFullClassName($message, '', $unscopedFile, $unused, $classname, $legacy, $fullname, $previous);
    if ($classname !== 'Example\\Messages\\ExportTraceServiceResponse') {
        fail('Unscoped descriptor class names were unexpectedly prefixed.');
    }

    $namespaceFile = new class {
        public function getPackage(): string
        {
            return 'opentelemetry.proto';
        }

        public function getOptions(): object
        {
            return new class {
                public function hasPhpNamespace(): bool
                {
                    return true;
                }

                public function getPhpClassPrefix(): string
                {
                    return '';
                }
            };
        }
    };
    $guardException = null;
    try {
        $gpbUtil::getFullClassName($message, '', $namespaceFile, $unused, $classname, $legacy, $fullname, $previous);
    } catch (RuntimeException $exception) {
        $guardException = $exception;
    }
    if ($guardException === null || $guardException->getMessage() !== 'OTLP generated descriptors unexpectedly define php_namespace.') {
        fail('Scoped GPBUtil php_namespace guard did not throw the expected exception.');
    }
}

function runResponsePhase(string $vendor, bool $expectSuccess): void
{
    registerScopedAutoloader($vendor);
    $response = responseClass();
    try {
        $message = new $response();
    } catch (Throwable $throwable) {
        if (!$expectSuccess && str_contains($throwable->getMessage(), 'is not found in descriptor pool')) {
            echo "unpatched scoped response construction failed as expected\n";
            return;
        }
        throw $throwable;
    }
    if (!$expectSuccess) {
        fail('The unpatched scoped response unexpectedly constructed successfully.');
    }

    $partial = new (partialSuccessClass())();
    $partial->setRejectedSpans(3);
    $payload = $message->setPartialSuccess($partial)->serializeToString();
    if ($payload === '') {
        fail('The generated partial-success response payload was empty.');
    }
    $decoded = new $response();
    $decoded->mergeFromString($payload);
    if ($decoded->getPartialSuccess()?->getRejectedSpans() !== 3) {
        fail('The patched scoped response did not decode rejected spans.');
    }
    assertDescriptorNames();
    echo "patched scoped response construction and non-empty decode passed\n";
}

function registerUnscopedAutoloader(string $vendor): void
{
    spl_autoload_register(static function (string $class) use ($vendor): void {
        $prefixes = [
            'OpenTelemetry\\SDK\\' => $vendor . '/open-telemetry/sdk/',
            'OpenTelemetry\\API\\' => $vendor . '/open-telemetry/api/',
            'OpenTelemetry\\' => $vendor . '/open-telemetry/',
            'Opentelemetry\\Proto\\' => $vendor . '/open-telemetry/gen-otlp-protobuf/Opentelemetry/Proto/',
            'GPBMetadata\\Google\\Protobuf\\' => $vendor . '/google/protobuf/src/GPBMetadata/Google/Protobuf/',
            'GPBMetadata\\' => $vendor . '/open-telemetry/gen-otlp-protobuf/GPBMetadata/',
            'Google\\Protobuf\\' => $vendor . '/google/protobuf/src/Google/Protobuf/',
            'Psr\\Log\\' => $vendor . '/psr/log/src/',
        ];
        foreach ($prefixes as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
                return;
            }
        }
    });
}

function runExporterCases(string $vendor): void
{
    registerUnscopedAutoloader($vendor);
    eval('namespace OpenTelemetry\\Distro\\OtlpExporters; function convert_spans(iterable $batch): string { return "fixture"; }');
    require __DIR__ . '/../../prod/php/OpenTelemetry/Contrib/Otlp/SpanExporter.php';
    putenv('OTEL_LOG_LEVEL=debug');
    $writer = new class implements \OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface {
        public array $messages = [];

        public function write($level, string $message, array $context): void
        {
            $this->messages[] = [$level, $message];
        }
    };
    \OpenTelemetry\API\Behavior\Internal\Logging::reset();
    \OpenTelemetry\API\Behavior\Internal\Logging::setLogWriter($writer);

    $response = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse();
    $partial = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTracePartialSuccess();
    $partial->setRejectedSpans(2);
    $rejectedPayload = $response->setPartialSuccess($partial)->serializeToString();
    $warning = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse();
    $warningPartial = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTracePartialSuccess();
    $warningPartial->setErrorMessage('accepted with warning');
    $warningPayload = $warning->setPartialSuccess($warningPartial)->serializeToString();
    $success = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceResponse();
    $successPartial = new \Opentelemetry\Proto\Collector\Trace\V1\ExportTracePartialSuccess();
    $successPayload = $success->setPartialSuccess($successPartial)->serializeToString();

    $cases = [
        'null response' => [null, true],
        'empty response' => ['', true],
        'non-empty success' => [$successPayload, true],
        'rejected partial success' => [$rejectedPayload, false],
        'warning partial success' => [$warningPayload, true],
        'malformed response' => ["\x0a\x01", false],
    ];
    foreach ($cases as $name => [$payload, $expected]) {
        $transport = new class ($payload) implements \OpenTelemetry\SDK\Common\Export\TransportInterface {
            public function __construct(private mixed $payload)
            {
            }

            public function contentType(): string
            {
                return 'application/x-protobuf';
            }

            public function send(string $payload, ?\OpenTelemetry\SDK\Common\Future\CancellationInterface $cancellation = null): \OpenTelemetry\SDK\Common\Future\FutureInterface
            {
                return new \OpenTelemetry\SDK\Common\Future\CompletedFuture($this->payload);
            }

            public function shutdown(?\OpenTelemetry\SDK\Common\Future\CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function forceFlush(?\OpenTelemetry\SDK\Common\Future\CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $writer->messages = [];
        $result = (new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport))->export([])->await();
        if ($result !== $expected) {
            fail("Exporter case '$name' returned " . var_export($result, true));
        }
        if (in_array($name, ['null response', 'empty response', 'non-empty success'], true) && $writer->messages !== []) {
            fail("Exporter case '$name' logged an unexpected message.");
        }
        $messages = array_column($writer->messages, 1);
        if ($name === 'rejected partial success' && !in_array('Export partial success', $messages, true)) {
            fail('Rejected partial-success logging was not emitted.');
        }
        if ($name === 'warning partial success' && !in_array('Export success with warnings/suggestions', $messages, true)) {
            fail('Warning partial-success logging was not emitted.');
        }
        if ($name === 'malformed response' && !in_array('Export failure', $messages, true)) {
            fail('Malformed response logging was not emitted.');
        }
        echo "exporter case passed: $name\n";
    }
}

function main(): void
{
    global $argv;
    runPatcherSourceCases();
    if (($argv[1] ?? null) === '--source-only') {
        return;
    }
    $fixtureRoot = $argv[1] ?? __DIR__ . '/../../_BUILT/php_code_for_packages';
    $scopedVendor = $fixtureRoot . '/scoped/81/vendor';
    $unscopedVendor = $fixtureRoot . '/not_scoped/vendor_81';
    if (!is_dir($scopedVendor) || !is_dir($unscopedVendor)) {
        fail('Built PHP 8.1 vendor fixtures are required.');
    }

    $temporary = __DIR__ . '/../../_BUILT/scoped_grpc_fixture_' . bin2hex(random_bytes(6));
    try {
        if (!mkdir($temporary, 0777, true) && !is_dir($temporary)) {
            fail("Unable to create temporary fixture: $temporary");
        }
        copyTree($scopedVendor . '/google/protobuf/src/Google/Protobuf', $temporary . '/google/protobuf/src/Google/Protobuf');
        copyTree($scopedVendor . '/google/protobuf/src/GPBMetadata/Google/Protobuf', $temporary . '/google/protobuf/src/GPBMetadata/Google/Protobuf');
        copyTree($scopedVendor . '/open-telemetry/gen-otlp-protobuf', $temporary . '/open-telemetry/gen-otlp-protobuf');

        $gpBUtil = $temporary . '/google/protobuf/src/Google/Protobuf/Internal/GPBUtil.php';
        $fixtureContent = file_get_contents($gpBUtil);
        if (!is_string($fixtureContent)) {
            fail('Unable to read temporary GPBUtil fixture.');
        }
        $fixMarker = 'OTEL scoped protobuf descriptor class fix';
        $fixCount = substr_count($fixtureContent, $fixMarker);
        if ($fixCount > 1) {
            fail('The scoped fixture contains duplicate GPBUtil repair markers.');
        }
        if ($fixCount === 1) {
            $fixStart = strpos($fixtureContent, '        // ' . $fixMarker);
            $fixEnd = strpos($fixtureContent, "    }\n    public static function combineInt32ToInt64", $fixStart);
            if ($fixStart === false || $fixEnd === false) {
                fail('Unable to remove the existing GPBUtil repair from the temporary fixture.');
            }
            $fixtureContent = substr($fixtureContent, 0, $fixStart) . substr($fixtureContent, $fixEnd);
            if (file_put_contents($gpBUtil, $fixtureContent) === false) {
                fail('Unable to normalize the temporary GPBUtil fixture.');
            }
        }
        $guard = "        if (\$option !== null && \$option->hasPhpNamespace() && \\str_starts_with(\$package, 'opentelemetry.')) {\n"
            . "            throw new \\RuntimeException('OTLP generated descriptors unexpectedly define php_namespace.');\n"
            . "        }\n";
        $fixtureContent = file_get_contents($gpBUtil);
        if (!is_string($fixtureContent)) {
            fail('Unable to reread temporary GPBUtil fixture.');
        }
        $guardCount = substr_count($fixtureContent, $guard);
        if ($guardCount > 1) {
            fail('The temporary GPBUtil fixture contains duplicate php_namespace guards.');
        }
        if ($guardCount === 1) {
            $fixtureContent = str_replace($guard, '', $fixtureContent);
            if (file_put_contents($gpBUtil, $fixtureContent) === false) {
                fail('Unable to normalize the temporary php_namespace guard.');
            }
        }

        $script = __FILE__;
        $command = static fn(string $phase): array => [PHP_BINARY, $script, $temporary, $phase];
        $run = static function (array $parts): array {
            $output = [];
            $code = 0;
            exec(implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1', $output, $code);
            return [$code, implode("\n", $output)];
        };

        [$baselineCode, $baselineOutput] = $run($command('baseline'));
        if ($baselineCode !== 0 || !str_contains($baselineOutput, 'failed as expected')) {
            fail("Unpatched regression did not fail as expected:\n$baselineOutput");
        }

        $config = require __DIR__ . '/../build/php-scoper.inc.php';
        /** @var list<Closure> $patchers */
        $patchers = $config['patchers'];
        $original = file_get_contents($gpBUtil);
        if (!is_string($original)) {
            fail('Unable to read temporary GPBUtil fixture.');
        }
        $patcher = $patchers[1];
        $patched = $patcher($gpBUtil, PREFIX, $original);
        if ($patched === $original || !str_contains($patched, $fixMarker)) {
            fail('Scoped GPBUtil patch did not change the temporary fixture.');
        }
        if (!str_contains($patched, 'mixed $class') || !str_contains($patched, '$scopeClass($legacy_classname)')) {
            fail('Scoped GPBUtil patch does not guard optional descriptor aliases.');
        }
        if (substr_count($patched, "throw new \\RuntimeException('OTLP generated descriptors unexpectedly define php_namespace.')") !== 1) {
            fail('Scoped GPBUtil patch did not inject exactly one php_namespace guard.');
        }
        if ($patcher($gpBUtil, PREFIX, $patched) !== $patched) {
            fail('Scoped GPBUtil patch is not idempotent.');
        }
        $customInput = str_replace('namespace ' . PREFIX . '\\Google\\Protobuf\\Internal', 'namespace CustomScope\\Google\\Protobuf\\Internal', $original);
        $customPrefix = $patcher($gpBUtil, 'CustomScope', $customInput);
        if (!str_contains($customPrefix, "'CustomScope\\\\'")) {
            fail('Scoped GPBUtil patch did not honor a custom prefix.');
        }
        $invalidShapes = [
            str_replace($marker = "    }\n    public static function combineInt32ToInt64", '', $original),
            $original . $marker,
        ];
        foreach ($invalidShapes as $invalidShape) {
            $threw = false;
            try {
                $patcher($gpBUtil, PREFIX, $invalidShape);
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'GPBUtil')) {
                    throw $exception;
                }
                $threw = true;
            }
            if (!$threw) {
                fail('Scoped GPBUtil patch accepted an invalid shape.');
            }
        }
        $unscopedPath = $unscopedVendor . '/google/protobuf/src/Google/Protobuf/Internal/GPBUtil.php';
        $unscoped = file_get_contents($unscopedPath);
        if (!is_string($unscoped) || $patcher($unscopedPath, PREFIX, $unscoped) !== $unscoped) {
            fail('Scoped GPBUtil patch changed unscoped output.');
        }
        if (file_put_contents($gpBUtil, $patched) === false) {
            fail('Unable to write temporary patched GPBUtil fixture.');
        }

        [$patchedCode, $patchedOutput] = $run($command('patched'));
        if ($patchedCode !== 0) {
            fail("Patched response regression failed:\n$patchedOutput");
        }
        echo $baselineOutput . "\n" . $patchedOutput . "\n";

        runExporterCases($unscopedVendor);
        echo "all scoped gRPC regressions passed\n";
    } finally {
        removeTree($temporary);
    }
}

$phase = $argv[2] ?? null;
if ($phase === 'baseline' || $phase === 'patched') {
    runResponsePhase($argv[1], $phase === 'patched');
    exit(0);
}

main();
