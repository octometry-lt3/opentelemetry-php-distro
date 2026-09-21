<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\Util\AssertEx;

/**
 * @group requires_external_services
 */
final class DeclarativeConfigGrpcTest extends ComponentTestCaseBase
{
    private const YAML_TEMPLATE_FILE = __DIR__ . '/TestData/declarative_config_grpc_test.yaml';
    private const EXPECTED_SERVICE_NAME = 'declarative-config-grpc-component-test';
    private const EXPECTED_CUSTOM_ATTRIBUTE_VALUE = 'test-value-from-grpc-yaml';

    public function testDeclarativeConfigGrpcExport(): void
    {
        $yamlContent = file_get_contents(self::YAML_TEMPLATE_FILE);
        self::assertNotFalse($yamlContent);
        $endpoint = getenv('OTEL_PHP_TESTS_OTLP_GRPC_RECEIVER_HOST') . ':' . getenv('OTEL_PHP_TESTS_OTLP_GRPC_RECEIVER_PORT');
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        $yamlConfigFile = tempnam(sys_get_temp_dir(), 'otel_decl_grpc_cfg_') . '.yaml';
        self::assertNotFalse(file_put_contents($yamlConfigFile, $yamlContent));

        $this->getTestCaseHandle()->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
            }
        )->execAppCode(AppCodeTarget::asRouted([self::class, 'appCodeEmpty']));

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

        AssertEx::notEmptyArray($traces);
        $span = $traces[0]['spans'][0] ?? [];
        $process = $traces[0]['processes'][$span['processID']] ?? [];
        self::assertSame(self::EXPECTED_SERVICE_NAME, $process['serviceName'] ?? null);
        $tags = array_column($process['tags'] ?? [], 'value', 'key');
        self::assertSame(self::EXPECTED_CUSTOM_ATTRIBUTE_VALUE, $tags['test.custom.attribute'] ?? null);
    }
}
