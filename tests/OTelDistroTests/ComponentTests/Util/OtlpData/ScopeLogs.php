<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use Opentelemetry\Proto\Logs\V1\ScopeLogs as OTelProtoScopeLogs;

final class ScopeLogs
{
    /** @param LogRecord[] $logRecords */
    public function __construct(
        public readonly ?InstrumentationScope $scope,
        public readonly array $logRecords,
        public readonly string $schemaUrl,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoScopeLogs $source): self
    {
        $scope = DeserializationUtil::deserializeNullableFromOTelProto($source->getScope(), InstrumentationScope::deserializeFromOTelProto(...));
        return new self(
            scope: $scope,
            logRecords: DeserializationUtil::deserializeArrayFromOTelProto(
                $source->getLogRecords(),
                fn($logRecord): LogRecord => LogRecord::deserializeFromOTelProto($logRecord, $scope?->name),
            ),
            schemaUrl: $source->getSchemaUrl(),
        );
    }
}
