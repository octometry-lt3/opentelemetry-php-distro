<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use Opentelemetry\Proto\Logs\V1\ResourceLogs as OTelProtoResourceLogs;

final class ResourceLogs
{
    /** @param ScopeLogs[] $scopeLogs */
    public function __construct(
        public readonly ?OTelResource $resource,
        public readonly array $scopeLogs,
        public readonly string $schemaUrl,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoResourceLogs $source): self
    {
        return new self(
            resource: DeserializationUtil::deserializeNullableFromOTelProto($source->getResource(), OTelResource::deserializeFromOTelProto(...)),
            scopeLogs: DeserializationUtil::deserializeArrayFromOTelProto($source->getScopeLogs(), ScopeLogs::deserializeFromOTelProto(...)),
            schemaUrl: $source->getSchemaUrl(),
        );
    }

    /** @return iterable<LogRecord> */
    public function logRecords(): iterable
    {
        foreach ($this->scopeLogs as $scopeLogs) {
            yield from $scopeLogs->logRecords;
        }
    }
}
