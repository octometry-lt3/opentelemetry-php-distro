<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use Opentelemetry\Proto\Logs\V1\LogRecord as OTelProtoLogRecord;
use PHPUnit\Framework\Assert;

final class LogRecord
{
    public function __construct(
        public readonly string $body,
        public readonly Attributes $attributes,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $instrumentationScopeName,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoLogRecord $source, ?string $instrumentationScopeName): self
    {
        $body = $source->getBody();
        Assert::assertNotNull($body);
        Assert::assertTrue($body->hasStringValue());
        return new self(
            body: $body->getStringValue(),
            attributes: Attributes::deserializeFromOTelProto($source->getAttributes()),
            traceId: $source->getTraceId(),
            spanId: $source->getSpanId(),
            instrumentationScopeName: $instrumentationScopeName,
        );
    }
}
