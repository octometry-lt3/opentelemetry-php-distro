<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use JsonException;
use OpenTelemetry\Distro\Log\NativeLogWriter;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionMethod;

final class NativeLogWriterTest extends TestCaseBase
{
    /**
     * @param array<array-key, mixed> $context
     */
    private static function formatMessage(string $message, array $context, bool $attachLogContext = true): string
    {
        $formatMessage = new ReflectionMethod(NativeLogWriter::class, 'formatMessage');

        return $formatMessage->invoke(null, $message, $context, $attachLogContext);
    }

    private static function assertSingleLine(string $message): void
    {
        self::assertStringNotContainsString("\n", $message);
        self::assertStringNotContainsString("\r", $message);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    private static function appendedContext(string $formattedMessage): array
    {
        $contextSeparator = ' context: ';
        $contextStart = strrpos($formattedMessage, $contextSeparator);
        self::assertNotFalse($contextStart);

        $context = substr($formattedMessage, $contextStart + strlen($contextSeparator));
        $decodedContext = json_decode($context, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedContext);

        return $decodedContext;
    }

    public function testFormatsSdkWarningWithCompactJsonContextOnOneLine(): void
    {
        $message = '[OTEL] WARNING: SDK warning contains a newline\nand a carriage return\r';
        $context = [
            'trace_id' => '0123456789abcdef',
            'span_id' => '01234567',
            'value' => "context newline\nand carriage return\r",
        ];

        $formattedMessage = self::formatMessage($message, $context);

        self::assertStringStartsWith('[OTEL] WARNING: SDK warning contains a newline\\nand a carriage return\\r context: {', $formattedMessage);
        self::assertStringNotContainsString('array (', $formattedMessage);
        self::assertSingleLine($formattedMessage);
        self::assertSame($context, self::appendedContext($formattedMessage));
    }

    public function testFormatsEmptyContextAsJsonArray(): void
    {
        $formattedMessage = self::formatMessage('[OTEL] WARNING: empty context', []);

        self::assertSame('[OTEL] WARNING: empty context context: []', $formattedMessage);
        self::assertSingleLine($formattedMessage);
        self::assertSame([], self::appendedContext($formattedMessage));
    }

    public function testDoesNotAppendContextWhenDisabled(): void
    {
        $formattedMessage = self::formatMessage("[OTEL] WARNING: no context\n", ['trace_id' => '0123456789abcdef'], false);

        self::assertSame('[OTEL] WARNING: no context\\n', $formattedMessage);
        self::assertSingleLine($formattedMessage);
    }

    public function testSubstitutesInvalidUtf8InContext(): void
    {
        $formattedMessage = self::formatMessage('[OTEL] WARNING: invalid UTF-8', ['value' => "invalid\xB1"]);

        self::assertSingleLine($formattedMessage);
        self::assertSame(['value' => "invalid�"], self::appendedContext($formattedMessage));
    }

    public function testRetainsWarningWhenContextCannotBeEncoded(): void
    {
        $recursiveContext = [];
        $recursiveContext['self'] = &$recursiveContext;

        $formattedMessage = self::formatMessage('[OTEL] WARNING: recursive context', $recursiveContext);

        self::assertStringStartsWith('[OTEL] WARNING: recursive context context: ', $formattedMessage);
        self::assertSingleLine($formattedMessage);
        self::assertArrayHasKey('error', self::appendedContext($formattedMessage));
    }
}
