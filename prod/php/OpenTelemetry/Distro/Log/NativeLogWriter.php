<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use JsonException;
use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface;

class NativeLogWriter implements LogWriterInterface
{
    private bool $attachLogContext;

    public function __construct()
    {
        $this->attachLogContext = Configuration::getBoolean('OTEL_PHP_LOG_OTEL_WITH_CONTEXT', true);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function write(mixed $level, string $message, array $context): void
    {
        $edotLevel = is_string($level) ? (LogLevel::fromPsrLevel($level) ?? LogLevel::off) : LogLevel::off;

        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3];

        $func = ($caller['class'] ?? '') . ($caller['type'] ?? '') . $caller['function'];
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpFullyQualifiedNameUsageInspection
         */
        \OpenTelemetry\Distro\log_feature(
            0 /* <- isForced */,
            $edotLevel->value,
            LogFeature::OTEL,
            $caller['file'] ?? '',
            $caller['line'] ?? null,
            $func,
            self::formatMessage($message, $context, $this->attachLogContext)
        );
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function formatMessage(string $message, array $context, bool $attachLogContext): string
    {
        $logContext = '';
        if ($attachLogContext) {
            try {
                $contextAsJson = json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $contextAsJson = '{"error":"Failed to JSON encode context"}';
            }
            $logContext = ' context: ' . $contextAsJson;
        }

        return str_replace(["\r", "\n"], ['\\r', '\\n'], $message . $logContext);
    }

    public static function enableLogWriter(): void
    {
        Logging::setLogWriter(new self());
    }
}
