<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\Logger;
use Override;
use PHPUnit\Framework\Assert;

final class WaitForOTelSignalCounts implements IsEnoughAgentBackendCommsInterface, LoggableInterface
{
    use LoggableTrait;

    private int $minSpanCount = 0;
    private int $maxSpanCount = 0;
    private int $minLogCount = 0;
    private int $maxLogCount = 0;

    private readonly Logger $logger;

    private function __construct()
    {
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));
    }

    /**
     * @param positive-int $min
     * @param ?positive-int $max
     */
    public static function spans(int $min, ?int $max = null): self
    {
        Assert::assertGreaterThan(0, $min);
        if ($max !== null) {
            Assert::assertGreaterThanOrEqual($min, $max);
        }

        $result = new WaitForOTelSignalCounts();
        $result->minSpanCount = $min;
        $result->maxSpanCount = $max ?? $min;

        return $result;
    }

    /**
     * @param positive-int $min
     */
    public static function spansAtLeast(int $min): self
    {
        return self::spans(min: $min, max: PHP_INT_MAX);
    }

    /**
     * @param positive-int $minSpanCount
     * @param positive-int $minLogCount
     */
    public static function spansAndLogs(int $minSpanCount, int $minLogCount = 1): self
    {
        Assert::assertGreaterThan(0, $minSpanCount);
        Assert::assertGreaterThan(0, $minLogCount);
        $result = new self();
        $result->minSpanCount = $minSpanCount;
        $result->maxSpanCount = $minSpanCount;
        $result->minLogCount = $minLogCount;
        $result->maxLogCount = $minLogCount;
        return $result;
    }

    /** @param positive-int $min */
    public static function logs(int $min = 1): self
    {
        Assert::assertGreaterThan(0, $min);
        $result = new self();
        $result->maxSpanCount = PHP_INT_MAX;
        $result->minLogCount = $min;
        $result->maxLogCount = $min;
        return $result;
    }

    #[Override]
    public function isEnough(AgentBackendComms $comms): bool
    {
        $spansCount = IterableUtil::count($comms->spans());
        $logsCount = IterableUtil::count($comms->logRecords());
        Assert::assertLessThanOrEqual($this->maxSpanCount, $spansCount);
        Assert::assertLessThanOrEqual($this->maxLogCount ?: PHP_INT_MAX, $logsCount);

        // If minSpanCount !== 0 then check that there is at least one root span
        $result = $this->minLogCount > 0
            ? $logsCount >= $this->minLogCount
            : ($spansCount >= $this->minSpanCount) && (($this->minSpanCount === 0) || self::isThereAtLeastOneRootSpan($comms->spans()));

        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Checked if exported data events counts reached the waited for values', compact('result', 'spansCount', 'logsCount', 'this'));

        return $result;
    }

    /**
     * @param iterable<Span> $spans
     */
    private static function isThereAtLeastOneRootSpan(iterable $spans): bool
    {
        return !IterableUtil::isEmpty(IterableUtil::findByPredicateOnValue($spans, fn($span) => $span->parentId === null));
    }
}
