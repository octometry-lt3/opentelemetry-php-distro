<?php

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

final class LaravelMessagingSemconvPatcher
{
    private const LARAVEL_AUTO_INSTRUMENTATION_VERSION = '1.9.0';
    private const SEMCONV_VERSION = '1.45.0';

    public static function patchAttributesBuilderContent(string $content): string
    {
        $awsSqsValue = 'MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_AWS_SQS';
        $awsSqsValueCount = substr_count($content, $awsSqsValue);
        if ($awsSqsValueCount !== 1) {
            throw new \RuntimeException("Expected 1 occurrence of {$awsSqsValue}, found {$awsSqsValueCount}");
        }
        $content = str_replace(
            $awsSqsValue,
            'MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME_VALUE_AWS_SQS',
            $content
        );

        $messagingSystemPattern = '~MessagingIncubatingAttributes::MESSAGING_SYSTEM(?=\s*=>)~';
        $messagingSystemCount = preg_match_all($messagingSystemPattern, $content);
        if ($messagingSystemCount !== 3) {
            throw new \RuntimeException(
                "Expected 3 Laravel queue messaging.system attributes, found {$messagingSystemCount}"
            );
        }
        $content = preg_replace(
            $messagingSystemPattern,
            'MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME',
            $content
        );
        if ($content === null) {
            throw new \RuntimeException('Failed to patch Laravel queue messaging.system attributes');
        }

        return $content;
    }

    public static function patchVendor(string $vendorDir): void
    {
        $installedFile = $vendorDir . '/composer/installed.php';
        if (!is_file($installedFile)) {
            throw new \RuntimeException("Composer installed metadata not found: {$installedFile}");
        }

        $installed = require $installedFile;
        if (!is_array($installed)) {
            throw new \RuntimeException("Invalid Composer installed metadata: {$installedFile}");
        }
        $versions = $installed['versions'] ?? null;
        if (!is_array($versions)) {
            throw new \RuntimeException("Invalid Composer installed metadata: {$installedFile}");
        }

        self::assertInstalledPackageVersion(
            $versions,
            'open-telemetry/opentelemetry-auto-laravel',
            self::LARAVEL_AUTO_INSTRUMENTATION_VERSION
        );
        self::assertInstalledPackageVersion($versions, 'open-telemetry/sem-conv', self::SEMCONV_VERSION);

        $attributesBuilderFile = $vendorDir
            . '/open-telemetry/opentelemetry-auto-laravel/src/Hooks/Illuminate/Queue/AttributesBuilder.php';
        $content = file_get_contents($attributesBuilderFile);
        if ($content === false) {
            throw new \RuntimeException("Unable to read Laravel queue attributes builder: {$attributesBuilderFile}");
        }

        $patchedContent = self::patchAttributesBuilderContent($content);
        if (file_put_contents($attributesBuilderFile, $patchedContent) === false) {
            throw new \RuntimeException("Unable to patch Laravel queue attributes builder: {$attributesBuilderFile}");
        }

        fwrite(
            STDOUT,
            'Patched Laravel queue instrumentation for Semantic Conventions ' . self::SEMCONV_VERSION . PHP_EOL
        );
    }

    /**
     * @param array<string, array<string, mixed>> $versions
     */
    private static function assertInstalledPackageVersion(
        array $versions,
        string $package,
        string $expectedVersion
    ): void {
        $actualVersion = $versions[$package]['pretty_version'] ?? null;
        if ($actualVersion !== $expectedVersion) {
            throw new \RuntimeException(
                "Expected {$package} {$expectedVersion}, found " . ($actualVersion ?? 'unknown')
            );
        }
    }
}
