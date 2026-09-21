<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Keboola\GoogleAds\ExtractionStats;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ExtractionStatsTest extends TestCase
{
    public function testRunWithoutAnyProcessedCustomerDoesNotFail(): void
    {
        $stats = new ExtractionStats();

        Assert::assertSame(0, $stats->getProcessedCustomersCount());
        Assert::assertSame(0, $stats->getFailedCustomersCount());
        Assert::assertFalse($stats->everyProcessedCustomerFailed());
    }

    public function testRunWithOnlySuccessfulCustomersDoesNotFail(): void
    {
        $stats = new ExtractionStats();
        $stats->customerSucceeded();
        $stats->customerSucceeded();

        Assert::assertSame(2, $stats->getProcessedCustomersCount());
        Assert::assertSame(0, $stats->getFailedCustomersCount());
        Assert::assertFalse($stats->everyProcessedCustomerFailed());
    }

    /**
     * The behaviour that manager (MCC) accounts depend on: single unreadable client accounts are
     * tolerated, and the job stays successful.
     */
    public function testRunWithSomeFailedCustomersDoesNotFail(): void
    {
        $stats = new ExtractionStats();
        $stats->customerSucceeded();
        $stats->customerFailed('Closed account', '1111111111', 'PERMISSION_DENIED');
        $stats->customerSucceeded();

        Assert::assertSame(3, $stats->getProcessedCustomersCount());
        Assert::assertSame(1, $stats->getFailedCustomersCount());
        Assert::assertFalse($stats->everyProcessedCustomerFailed());
    }

    public function testRunWithOneFailedCustomerFails(): void
    {
        $stats = new ExtractionStats();
        $stats->customerFailed('Only account', '1111111111', 'Unrecognized field in the query.');

        Assert::assertTrue($stats->everyProcessedCustomerFailed());

        $message = $stats->getEveryCustomerFailedMessage();
        Assert::assertStringContainsString('all 1 processed Google Ads account,', $message);
        Assert::assertStringContainsString('Errors: "Only account" (ID "1111111111")', $message);
        Assert::assertStringContainsString('Unrecognized field in the query.', $message);
    }

    public function testRunWithEveryCustomerFailedFails(): void
    {
        $stats = new ExtractionStats();
        $stats->customerFailed('HOLY A', '1111111111', 'Unrecognized field in the query.');
        $stats->customerFailed('HOLY B', '2222222222', 'Unrecognized field in the query.');

        Assert::assertSame(2, $stats->getProcessedCustomersCount());
        Assert::assertSame(2, $stats->getFailedCustomersCount());
        Assert::assertTrue($stats->everyProcessedCustomerFailed());

        $message = $stats->getEveryCustomerFailedMessage();
        Assert::assertStringContainsString('all 2 processed Google Ads accounts', $message);
        Assert::assertStringContainsString('HOLY A', $message);
        Assert::assertStringContainsString('HOLY B', $message);
    }

    /**
     * A manager account can hold hundreds of client accounts. The error message must stay
     * readable, so it lists only the first few failures.
     */
    public function testMessageListsOnlyTheFirstFailures(): void
    {
        $stats = new ExtractionStats();
        for ($i = 1; $i <= 5; $i++) {
            $stats->customerFailed(sprintf('Account %d', $i), sprintf('%d', $i), 'INVALID_ARGUMENT');
        }

        Assert::assertTrue($stats->everyProcessedCustomerFailed());

        $message = $stats->getEveryCustomerFailedMessage();
        Assert::assertStringContainsString('all 5 processed Google Ads accounts', $message);
        Assert::assertStringContainsString('First 3 errors:', $message);
        Assert::assertStringContainsString('Account 3', $message);
        Assert::assertStringNotContainsString('Account 4', $message);
    }
}
