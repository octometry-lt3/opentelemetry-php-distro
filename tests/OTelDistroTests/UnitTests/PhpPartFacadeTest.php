<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\Distro\PhpPartFacade;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionMethod;

final class PhpPartFacadeTest extends TestCaseBase
{
    public function testHydratesMissingServerEnvironmentValuesWithoutOverwritingExistingValues(): void
    {
        $environmentNames = ['OTEL_SERVICE_NAME', 'DEPLOYMENT_TENANT_ID', 'OTEL_GRPC_ENDPOINT'];
        $originalServer = $_SERVER;
        $originalEnvironment = [];
        try {
            foreach ($environmentNames as $name) {
                $originalEnvironment[$name] = getenv($name);
                putenv($name . '=php-distro-test-' . strtolower($name));
                unset($_SERVER[$name]);
            }
            $_SERVER['OTEL_SERVICE_NAME'] = 'sapi-service-name';
            $_SERVER['UNRELATED_SERVER_VALUE'] = 'unchanged';

            $hydrateServerEnv = new ReflectionMethod(PhpPartFacade::class, 'hydrateServerEnvFromProcessEnv');
            $hydrateServerEnv->invoke(null);

            self::assertSame('sapi-service-name', $_SERVER['OTEL_SERVICE_NAME']);
            self::assertSame('php-distro-test-deployment_tenant_id', $_SERVER['DEPLOYMENT_TENANT_ID']);
            self::assertSame('php-distro-test-otel_grpc_endpoint', $_SERVER['OTEL_GRPC_ENDPOINT']);
            self::assertSame('unchanged', $_SERVER['UNRELATED_SERVER_VALUE']);
        } finally {
            $_SERVER = $originalServer;
            foreach ($originalEnvironment as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }
}
