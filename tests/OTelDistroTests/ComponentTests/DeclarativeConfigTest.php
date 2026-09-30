<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\ComponentTestsPHPUnitExtension;
use OTelDistroTests\ComponentTests\Util\HttpServerHandle;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use RuntimeException;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\TelemetryIncubatingAttributes;

/**
 * @group does_not_require_external_services
 */
final class DeclarativeConfigTest extends ComponentTestCaseBase
{
    private const YAML_TEMPLATE_FILE = __DIR__ . '/TestData/declarative_config_test.yaml';
    private const EXPECTED_SERVICE_NAME = 'declarative-config-component-test';
    private const EXPECTED_TENANT_ID = 'declarative-config-component-test-tenant';
    private const EXPECTED_CUSTOM_ATTRIBUTE_VALUE = 'test-value-from-yaml';

    private function buildYamlConfigFile(): string
    {
        $mockCollectorPort = ComponentTestsPHPUnitExtension::getGlobalTestInfra()->getMockOTelCollector()->getPortForAgent();
        /** @noinspection HttpUrlsUsage */
        $endpoint = 'http://' . HttpServerHandle::CLIENT_LOCALHOST_ADDRESS . ':' . $mockCollectorPort;
        $yamlContent = file_get_contents(self::YAML_TEMPLATE_FILE);
        self::assertNotFalse($yamlContent);
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        $tmpFile = tempnam(sys_get_temp_dir(), 'otel_decl_cfg_') . '.yaml';
        file_put_contents($tmpFile, $yamlContent);
        return $tmpFile;
    }

    private function implTestDeclarativeConfigResourceAttributes(): void
    {
        $yamlConfigFile = $this->buildYamlConfigFile();

        // Pre-initialize app code host with OTEL_CONFIG_FILE env var
        // ensureMainAppCodeHost is lazy - subsequent calls return the same instance
        $this->getTestCaseHandle()->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_SERVICE_NAME', self::EXPECTED_SERVICE_NAME);
                $appCodeHostParams->setAdditionalEnvVar('DEPLOYMENT_TENANT_ID', self::EXPECTED_TENANT_ID);
            }
        );

        self::implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap([]),
            subAppCode: [self::class, 'appCodeInspectLoggerProvider'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput): void {
                $resourceAttributes = $appCodeAuxOutput->getArray('loggerProviderResourceAttributes');
                self::assertSame(self::EXPECTED_SERVICE_NAME, $resourceAttributes['service.name'] ?? null);
                self::assertSame(self::EXPECTED_TENANT_ID, $resourceAttributes['saas.tenant.id'] ?? null);
                $resources = IterableUtil::toList($agentBackendComms->resources());
                $dbgCtx->add(compact('resources'));
                AssertEx::isPositiveInt(count($resources));

                $resourceAttributesExpectations = new AttributesExpectations(
                    attributes: [
                        ServiceAttributes::SERVICE_NAME                         => self::EXPECTED_SERVICE_NAME,
                        'test.custom.attribute'                                 => self::EXPECTED_CUSTOM_ATTRIBUTE_VALUE,
                        TelemetryIncubatingAttributes::TELEMETRY_DISTRO_NAME    => 'opentelemetry-php-distro',
                    ],
                );

                foreach ($resources as $resource) {
                    $resourceAttributesExpectations->assertMatches($resource->attributes);
                }
            }
        );
    }

    /** @return array<string, mixed> */
    public static function appCodeInspectLoggerProvider(): array
    {
        $globalsClass = 'OTelDistroScoped\\OpenTelemetry\\API\\Globals';
        if (!class_exists($globalsClass)) {
            throw new RuntimeException('The scoped OpenTelemetry API Globals class is unavailable.');
        }
        /** @var class-string<\OpenTelemetry\API\Globals> $globalsClass */
        $loggerProvider = $globalsClass::loggerProvider();
        if (!str_starts_with($loggerProvider::class, 'OTelDistroScoped\\')) {
            throw new RuntimeException('The scoped OpenTelemetry API did not return a scoped logger provider.');
        }
        if (!method_exists($loggerProvider, 'forceFlush') || $loggerProvider->forceFlush() !== true) {
            throw new RuntimeException('The scoped logger provider did not flush successfully.');
        }

        foreach ((new \ReflectionObject($loggerProvider))->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($loggerProvider);
            if (is_object($value) && method_exists($value, 'getResource')) {
                return ['loggerProviderResourceAttributes' => $value->getResource()->getAttributes()->toArray()];
            }
            if (is_object($value) && method_exists($value, 'getAttributes') && str_ends_with($property->getName(), 'resource')) {
                return ['loggerProviderResourceAttributes' => $value->getAttributes()->toArray()];
            }
        }

        throw new RuntimeException('The scoped logger provider resource was unavailable.');
    }

    public function testDeclarativeConfigResourceAttributes(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                $this->implTestDeclarativeConfigResourceAttributes();
            }
        );
    }
}
