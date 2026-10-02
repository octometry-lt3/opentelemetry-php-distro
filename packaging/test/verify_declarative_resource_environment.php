<?php

declare(strict_types=1);

/**
 * Verifies declarative configuration in an installed package.
 *
 * Invoke this with OTEL_CONFIG_FILE and the three process environment values
 * already set. The Distro bootstrap must see those values before this script
 * starts, so this intentionally does not set them from PHP.
 */

$options = getopt('', [
    'config:',
    'service-name:',
    'tenant-id:',
    'grpc-endpoint:',
    'report::',
]);

$requiredOptions = ['config', 'service-name', 'tenant-id', 'grpc-endpoint'];
foreach ($requiredOptions as $option) {
    if (!isset($options[$option]) || $options[$option] === '') {
        fwrite(STDERR, "Missing required option --{$option}.\n");
        exit(2);
    }
}

$lines = [];
$write = static function (string $message) use (&$lines): void {
    $lines[] = $message;
    fwrite(STDOUT, $message . PHP_EOL);
};

$fail = static function (string $message) use ($write, $options, &$lines): never {
    $write('FAILED: ' . $message);
    if (isset($options['report']) && $options['report'] !== false) {
        file_put_contents((string)$options['report'], implode(PHP_EOL, $lines) . PHP_EOL);
    }
    exit(1);
};

$configFile = (string)$options['config'];
if (!is_file($configFile) || !is_readable($configFile)) {
    $fail("declarative configuration file is not readable: {$configFile}");
}
$config = file_get_contents($configFile);
if ($config === false) {
    $fail("declarative configuration file could not be read: {$configFile}");
}
foreach (['OTEL_SERVICE_NAME', 'DEPLOYMENT_TENANT_ID', 'OTEL_GRPC_ENDPOINT'] as $variable) {
    if (!str_contains($config, '${' . $variable)) {
        $fail(sprintf('declarative configuration does not contain the expected ${%s} expression', $variable));
    }
}

$configuredFile = getenv('OTEL_CONFIG_FILE');
if ($configuredFile !== $configFile) {
    $fail(sprintf('OTEL_CONFIG_FILE is %s, expected %s', var_export($configuredFile, true), $configFile));
}

$expectedEnvironment = [
    'OTEL_SERVICE_NAME' => (string)$options['service-name'],
    'DEPLOYMENT_TENANT_ID' => (string)$options['tenant-id'],
    'OTEL_GRPC_ENDPOINT' => (string)$options['grpc-endpoint'],
];
foreach ($expectedEnvironment as $name => $expectedValue) {
    $actualValue = getenv($name);
    if ($actualValue === false || $actualValue === '' || $actualValue !== $expectedValue || str_contains($actualValue, '${')) {
        $fail(sprintf('%s is unresolved or unexpected: got %s, expected %s', $name, var_export($actualValue, true), $expectedValue));
    }
}

$write("Configuration: {$configFile}");
$write('Environment substitutions are present before provider inspection.');

try {
    $globalsClass = 'OTelDistroScoped\\OpenTelemetry\\API\\Globals';
    if (!class_exists($globalsClass)) {
        $fail("scoped Globals class {$globalsClass} is unavailable; package bootstrap or configuration parsing failed");
    }

    /** @var class-string $globalsClass */
    $loggerProvider = $globalsClass::loggerProvider();
    $providerClass = $loggerProvider::class;
    $write("Logger provider: {$providerClass}");
    if (str_contains($providerClass, 'NoopLoggerProvider')) {
        $fail('declarative configuration produced NoopLoggerProvider');
    }

    $walkObjects = static function (object $root, callable $visit): void {
        $visited = new SplObjectStorage();
        $walk = static function (object $object, int $depth) use (&$walk, $visited, $visit): void {
            if ($depth > 16 || $visited->contains($object)) {
                return;
            }
            $visited->attach($object);
            $visit($object);
            foreach ((new ReflectionObject($object))->getProperties() as $property) {
                $property->setAccessible(true);
                $value = $property->getValue($object);
                if (is_object($value)) {
                    $walk($value, $depth + 1);
                } elseif (is_array($value)) {
                    foreach ($value as $arrayValue) {
                        if (is_object($arrayValue)) {
                            $walk($arrayValue, $depth + 1);
                        }
                    }
                }
            }
        };
        $walk($root, 0);
    };

    $logsExporter = null;
    $walkObjects($loggerProvider, static function (object $object) use (&$logsExporter): void {
        $class = $object::class;
        if (str_starts_with($class, 'OTelDistroScoped\\')
            && str_contains($class, '\\Otlp\\')
            && str_ends_with($class, '\\LogsExporter')
        ) {
            $logsExporter = $object;
        }
    });
    if (!is_object($logsExporter)) {
        $fail('scoped OTLP logs exporter was not found in the logger provider object graph');
    }

    $grpcTransport = null;
    $walkObjects($logsExporter, static function (object $object) use (&$grpcTransport): void {
        $class = $object::class;
        if (str_starts_with($class, 'OTelDistroScoped\\')
            && str_contains($class, '\\Otlp\\')
            && str_ends_with($class, '\\GrpcTransport')
        ) {
            $grpcTransport = $object;
        }
    });
    if (!is_object($grpcTransport)) {
        $fail('scoped OTLP logs exporter gRPC transport was not found below the logs exporter');
    }

    $endpoint = null;
    foreach ((new ReflectionObject($grpcTransport))->getProperties() as $property) {
        if ($property->getName() !== 'endpoint') {
            continue;
        }
        $property->setAccessible(true);
        $endpoint = $property->getValue($grpcTransport);
        break;
    }
    if (!is_string($endpoint)) {
        $fail('scoped OTLP logs exporter gRPC transport endpoint property was not found');
    }
    $expectedGrpcEndpoint = (string)$options['grpc-endpoint'];
    if ($endpoint !== $expectedGrpcEndpoint) {
        $fail(sprintf(
            'scoped OTLP logs exporter gRPC endpoint mismatch: configured %s, expected %s',
            var_export($endpoint, true),
            var_export($expectedGrpcEndpoint, true)
        ));
    }
    $write('Scoped OTLP logs exporter gRPC endpoint matches --grpc-endpoint.');

    $resource = null;
    foreach ((new ReflectionObject($loggerProvider))->getProperties() as $property) {
        $property->setAccessible(true);
        $value = $property->getValue($loggerProvider);
        if (is_object($value) && method_exists($value, 'getResource')) {
            $resource = $value->getResource();
            break;
        }
        if (is_object($value) && method_exists($value, 'getAttributes') && str_ends_with($property->getName(), 'resource')) {
            $resource = $value;
            break;
        }
    }
    if (!is_object($resource) || !method_exists($resource, 'getAttributes')) {
        $fail('logger provider resource was unavailable; provider construction likely failed');
    }

    $attributes = $resource->getAttributes()->toArray();
    $write('Resource: ' . json_encode($attributes, JSON_THROW_ON_ERROR));
    if (($attributes['service.name'] ?? null) !== $expectedEnvironment['OTEL_SERVICE_NAME']) {
        $fail('service.name does not match OTEL_SERVICE_NAME');
    }
    if (($attributes['saas.tenant.id'] ?? null) !== $expectedEnvironment['DEPLOYMENT_TENANT_ID']) {
        $fail('saas.tenant.id does not match DEPLOYMENT_TENANT_ID');
    }
    $write('Declarative resource environment verification passed.');
} catch (Throwable $throwable) {
    $fail(sprintf(
        'parser/provider inspection threw %s: %s\n%s',
        $throwable::class,
        $throwable->getMessage(),
        $throwable->getTraceAsString()
    ));
}

if (isset($options['report']) && $options['report'] !== false) {
    file_put_contents((string)$options['report'], implode(PHP_EOL, $lines) . PHP_EOL);
}
