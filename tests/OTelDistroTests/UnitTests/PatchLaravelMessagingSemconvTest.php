<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\DistroTools\Build\LaravelMessagingSemconvPatcher;
use OTelDistroTests\Util\TestCaseBase;

final class PatchLaravelMessagingSemconvTest extends TestCaseBase
{
    public function testUpdatesLaravelQueueMessagingAttributeNames(): void
    {
        $source = <<<'PHP'
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'beanstalk',
            MessagingIncubatingAttributes::MESSAGING_SYSTEM => 'redis',
            MessagingIncubatingAttributes::MESSAGING_SYSTEM
                => MessagingIncubatingAttributes::MESSAGING_SYSTEM_VALUE_AWS_SQS,
            PHP;

        $expected = <<<'PHP'
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'beanstalk',
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME => 'redis',
            MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME
                => MessagingIncubatingAttributes::MESSAGING_SYSTEM_NAME_VALUE_AWS_SQS,
            PHP;

        self::assertSame($expected, LaravelMessagingSemconvPatcher::patchAttributesBuilderContent($source));
    }

    public function testRejectsUnexpectedUpstreamShape(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected 1 occurrence');

        LaravelMessagingSemconvPatcher::patchAttributesBuilderContent('');
    }
}
