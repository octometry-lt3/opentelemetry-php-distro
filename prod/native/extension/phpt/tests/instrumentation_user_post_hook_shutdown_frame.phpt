--TEST--
instrumentation - post hook uses the observed frame during shutdown
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=info
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
--FILE--
<?php
declare(strict_types=1);

function shutdownTarget(string $value): string {
    return $value;
}

\OpenTelemetry\Distro\hook(null, 'shutdownTarget', null, static function (
    $scope,
    array $args,
    $returnValue,
    $exception,
    $declaringScope,
    string $functionName,
    string $file,
    int $line
): void {
    echo "post:$functionName:$args[0]:$returnValue\n";
});

register_shutdown_function(static function (): void {
    echo shutdownTarget('shutdown'), "\n";
});

exit;
?>
--EXPECT--
post:shutdownTarget:shutdown:shutdown
shutdown
