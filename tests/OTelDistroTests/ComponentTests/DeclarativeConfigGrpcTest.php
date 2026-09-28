<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\ProcessUtil;
use RuntimeException;

/**
 * @group requires_external_services
 */
final class DeclarativeConfigGrpcTest extends ComponentTestCaseBase
{
    private const YAML_TEMPLATE_FILE = __DIR__ . '/TestData/declarative_config_grpc_test.yaml';
    private const EXPECTED_SERVICE_NAME = 'declarative-config-grpc-component-test';
    private const EXPECTED_CUSTOM_ATTRIBUTE_VALUE = 'test-value-from-grpc-yaml';
    private const APP_SPAN_NAME = 'declarative-config-grpc-application-span';

    public function testDeclarativeConfigGrpcExport(): void
    {
        if (
            getenv('OTEL_PHP_TESTS_PACKAGE_TYPE') !== 'deb'
            || getenv('OTEL_PHP_TESTS_PHP_VERSION') !== '8.1'
            || getenv('OTEL_PHP_TESTS_INSTALLER_BACKED') !== 'true'
        ) {
            self::markTestSkipped('OTLP/gRPC component coverage applies only to the installer-backed DEB PHP 8.1 row.');
        }

        $yamlContent = file_get_contents(self::YAML_TEMPLATE_FILE);
        self::assertNotFalse($yamlContent);
        $endpoint = getenv('OTEL_PHP_TESTS_OTLP_GRPC_RECEIVER_HOST') . ':' . getenv('OTEL_PHP_TESTS_OTLP_GRPC_RECEIVER_PORT');
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        $yamlConfigFile = tempnam(sys_get_temp_dir(), 'otel_decl_grpc_cfg_') . '.yaml';
        self::assertNotFalse(file_put_contents($yamlConfigFile, $yamlContent));

        $appCodeHost = $this->getTestCaseHandle()->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_PHP_LOG_LEVEL_STDERR', 'debug');
                $appCodeHostParams->setAdditionalEnvVar('OTEL_PHP_LOG_DESTINATION', 'stderr');
            }
        );
        $exitCode = $appCodeHost->execAppCode(AppCodeTarget::asRouted([self::class, 'appCodeExportsSpan']));
        $childProcessOutput = self::readChildProcessOutput($appCodeHost->appCodeHostParams);
        self::assertSame(0, $exitCode, "The app-code process failed. Child process stderr/stdout:\n$childProcessOutput");
        self::assertStringNotContainsString('is not found in descriptor pool', $childProcessOutput);
        self::assertStringNotContainsString('Export failure', $childProcessOutput);
        self::assertStringNotContainsString('Span exporter factory not defined', $childProcessOutput);
        self::assertStringNotContainsString('Error during opentelemetry initialization', $childProcessOutput);
        self::assertStringNotContainsString('Unhandled export error', $childProcessOutput);

        $queryHost = getenv('OTEL_PHP_TESTS_OTLP_GRPC_QUERY_HOST');
        $queryPort = getenv('OTEL_PHP_TESTS_OTLP_GRPC_QUERY_PORT');
        $queryUrl = sprintf(
            'http://%s:%s/api/traces?service=%s&limit=20',
            $queryHost,
            $queryPort,
            rawurlencode(self::EXPECTED_SERVICE_NAME),
        );
        $traces = [];
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $response = @file_get_contents($queryUrl, false, stream_context_create(['http' => ['timeout' => 2]]));
            if ($response !== false) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) && $decoded['data'] !== []) {
                    $traces = $decoded['data'];
                    break;
                }
            }
            usleep(500000);
        }

        self::assertNotEmpty(
            $traces,
            "The OTLP/gRPC receiver did not return a trace. Child process stderr/stdout:\n$childProcessOutput"
        );
        self::assertNotEmpty(
            $traces[0]['spans'] ?? [],
            "The OTLP/gRPC receiver did not receive a span. Child process stderr/stdout:\n$childProcessOutput"
        );
        self::assertContains(
            self::APP_SPAN_NAME,
            array_column($traces[0]['spans'], 'operationName'),
            "The OTLP/gRPC receiver did not receive the named application span. Child process stderr/stdout:\n$childProcessOutput"
        );
        $span = $traces[0]['spans'][0] ?? [];
        $process = $traces[0]['processes'][$span['processID']] ?? [];
        self::assertSame(self::EXPECTED_SERVICE_NAME, $process['serviceName'] ?? null);
        $tags = array_column($process['tags'] ?? [], 'value', 'key');
        self::assertSame(self::EXPECTED_CUSTOM_ATTRIBUTE_VALUE, $tags['test.custom.attribute'] ?? null);
    }

    public static function appCodeExportsSpan(): void
    {
        $scopedGlobalsClass = 'OTelDistroScoped\\OpenTelemetry\\API\\Globals';
        if (!class_exists($scopedGlobalsClass)) {
            throw new RuntimeException('The scoped OpenTelemetry API Globals class is unavailable.');
        }
        /** @var class-string<\OpenTelemetry\API\Globals> $scopedGlobalsClass */
        $tracerProvider = $scopedGlobalsClass::tracerProvider();
        if (!str_starts_with($tracerProvider::class, 'OTelDistroScoped\\')) {
            throw new RuntimeException('The scoped OpenTelemetry API did not return a scoped tracer provider.');
        }

        $tracerProvider->getTracer(self::class)
            ->spanBuilder(self::APP_SPAN_NAME)
            ->startSpan()
            ->end();

        if (!method_exists($tracerProvider, 'forceFlush') || call_user_func([$tracerProvider, 'forceFlush']) !== true) {
            throw new RuntimeException('The scoped tracer provider did not flush successfully.');
        }
    }

    private static function readChildProcessOutput(AppCodeHostParams $appCodeHostParams): string
    {
        $path = ProcessUtil::buildStdErrOutFileFullPath($appCodeHostParams->dbgProcessNamePrefix . '_1');
        if ($path === null || !file_exists($path)) {
            self::fail('The app-code child-process stderr/stdout log is unavailable.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            self::fail('The app-code child-process stderr/stdout log could not be read.');
        }

        return $contents;
    }
}
