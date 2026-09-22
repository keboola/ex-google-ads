<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Keboola\GoogleAds\ExtractionStats;
use PHPUnit\Framework\TestCase;

class ExtractionStatsTest extends TestCase
{
    public function testNoAccountsProcessedIsNotAFailure(): void
    {
        $stats = new ExtractionStats();
        self::assertFalse($stats->everyProcessedCustomerFailed());
    }

    public function testAllFailed(): void
    {
        $stats = new ExtractionStats();
        $stats->customerFailed('A', '111', 'boom');
        $stats->customerFailed('B', '222', 'boom');
        self::assertTrue($stats->everyProcessedCustomerFailed());
        self::assertSame(2, $stats->getFailedCustomersCount());
        self::assertSame(2, $stats->getProcessedCustomersCount());
    }

    public function testPartialFailureIsNotAllFailed(): void
    {
        $stats = new ExtractionStats();
        $stats->customerSucceeded();
        $stats->customerFailed('B', '222', 'boom');
        self::assertFalse($stats->everyProcessedCustomerFailed());
    }

    public function testMessageListsFailuresAndTruncatesAtLimit(): void
    {
        $stats = new ExtractionStats();
        $stats->customerFailed('A', '111', 'e1');
        $stats->customerFailed('B', '222', 'e2');
        $stats->customerFailed('C', '333', 'e3');
        $stats->customerFailed('D', '444', 'e4');
        $message = $stats->getEveryCustomerFailedMessage();
        self::assertStringContainsString('all 4 processed Google Ads accounts', $message);
        self::assertStringContainsString('First 3 errors', $message);
        self::assertStringContainsString('"A" (ID "111"): e1', $message);
        self::assertStringNotContainsString('"D" (ID "444"): e4', $message);
    }
}
