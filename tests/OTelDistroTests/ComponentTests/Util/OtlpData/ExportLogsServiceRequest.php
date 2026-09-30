<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use OTelDistroTests\Util\IterableUtil;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest as OTelProtoExportLogsServiceRequest;

final class ExportLogsServiceRequest
{
    /** @param ResourceLogs[] $resourceLogs */
    public function __construct(
        public readonly array $resourceLogs,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoExportLogsServiceRequest $source): self
    {
        return new self(
            resourceLogs: DeserializationUtil::deserializeArrayFromOTelProto($source->getResourceLogs(), ResourceLogs::deserializeFromOTelProto(...)),
        );
    }

    /** @return iterable<LogRecord> */
    public function logRecords(): iterable
    {
        foreach ($this->resourceLogs as $resourceLogs) {
            yield from $resourceLogs->logRecords();
        }
    }

    /** @return iterable<OTelResource> */
    public function resources(): iterable
    {
        foreach ($this->resourceLogs as $resourceLogs) {
            if ($resourceLogs->resource !== null) {
                yield $resourceLogs->resource;
            }
        }
    }

    public function isEmptyAfterDeserialization(): bool
    {
        return IterableUtil::isEmpty($this->logRecords());
    }
}
