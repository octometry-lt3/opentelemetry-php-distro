<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\ComponentTests\Util\OtlpData\ExportLogsServiceRequest;
use OTelDistroTests\ComponentTests\Util\OtlpData\LogRecord;
use OTelDistroTests\ComponentTests\Util\OtlpData\OTelResource;
use OTelDistroTests\Util\Log\LoggableTrait;
use OpenTelemetry\Contrib\Otlp\ProtobufSerializer;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest as OTelProtoExportLogsServiceRequest;
use Override;

final class IntakeLogsDataRequest extends IntakeDataRequestDeserialized
{
    use LoggableTrait;

    private function __construct(
        IntakeDataRequestRaw $raw,
        private readonly ExportLogsServiceRequest $deserialized,
    ) {
        parent::__construct($raw);
    }

    public static function deserializeFromRaw(IntakeDataRequestRaw $raw): self
    {
        $otelProtoRequest = new OTelProtoExportLogsServiceRequest();
        ProtobufSerializer::getDefault()->hydrate($otelProtoRequest, $raw->body);

        return new self($raw, ExportLogsServiceRequest::deserializeFromOTelProto($otelProtoRequest));
    }

    #[Override]
    public function isEmptyAfterDeserialization(): bool
    {
        return $this->deserialized->isEmptyAfterDeserialization();
    }

    /** @return iterable<LogRecord> */
    public function logRecords(): iterable
    {
        yield from $this->deserialized->logRecords();
    }

    /** @return iterable<OTelResource> */
    public function resources(): iterable
    {
        yield from $this->deserialized->resources();
    }
}
