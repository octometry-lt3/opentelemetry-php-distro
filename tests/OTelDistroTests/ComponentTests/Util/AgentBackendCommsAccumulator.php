<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use PHPUnit\Framework\Assert;

final class AgentBackendCommsAccumulator implements LoggableInterface
{
    use LoggableTrait;

    /** @var list<AgentBackendCommEvent> */
    private array $events = [];

    /** @var list<AgentBackendConnection> */
    private array $closedConnections = [];

    private ?AgentBackendConnectionStarted $openConnectionStart = null;

    /** @var list<IntakeDataRequestDeserialized> */
    private array $openConnectionRequests = [];

    private ?AgentBackendComms $cachedResult = null;

    /**
     * @param iterable<AgentBackendCommEvent> $events
     */
    public function addEvents(iterable $events): void
    {
        $this->cachedResult = null;

        foreach ($events as $event) {
            match (true) {
                $event instanceof AgentBackendConnectionStarted => $this->onConnectionStarted($event),
                $event instanceof IntakeDataRequestRaw => $this->onIntakeDataRequest($event),
                default => throw new ComponentTestsInfraException('Unexpected event type: ' . get_debug_type($events) . '; ' . LoggableToString::convert(compact('event'))),
            };
        }
    }

    private function onConnectionStarted(AgentBackendConnectionStarted $event): void
    {
        if ($this->openConnectionStart === null) {
            Assert::assertCount(0, $this->openConnectionRequests);
        } else {
            $this->closedConnections[] = new AgentBackendConnection($this->openConnectionStart, $this->openConnectionRequests);
            $this->openConnectionRequests = [];
        }

        $this->openConnectionStart = $event;
    }

    private function onIntakeDataRequest(IntakeDataRequestRaw $requestRaw): void
    {
        $this->openConnectionRequests[] = self::deserializeIntakeDataRequestBody($requestRaw);
    }

    public static function deserializeIntakeDataRequestBody(IntakeDataRequestRaw $requestRaw): IntakeDataRequestDeserialized
    {
        return match ($requestRaw->signalType) {
            OTelSignalType::trace => IntakeTraceDataRequest::deserializeFromRaw($requestRaw),
            OTelSignalType::log => IntakeLogsDataRequest::deserializeFromRaw($requestRaw),
            default => throw new ComponentTestsInfraException('Unexpected OTel signal type: ' . $requestRaw->signalType->name),
        };
    }

    public function isEnough(IsEnoughAgentBackendCommsInterface $isEnoughAgentBackendComms): bool
    {
        return $isEnoughAgentBackendComms->isEnough($this->getResult());
    }

    public function getResult(): AgentBackendComms
    {
        if ($this->cachedResult === null) {
            $connections = $this->closedConnections;
            if ($this->openConnectionStart !== null) {
                $connections[] = new AgentBackendConnection($this->openConnectionStart, $this->openConnectionRequests);
            }
            $this->cachedResult = new AgentBackendComms($this->events, $connections);
        }

        return $this->cachedResult;
    }
}
