<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

/**
 * Counts the Google Ads accounts processed by one run of the component, and the accounts whose
 * extraction failed.
 *
 * The extractor is built for manager accounts that hold many client accounts, where single
 * accounts are regularly unreadable: closed, suspended, or not a Google Ads account at all. One
 * unreadable account must not stop the extraction of the other accounts, so Extractor logs the
 * failure and continues. That per-account rule also applied when every account failed, for
 * example when the configured query asks for a field that the API does not accept. The component
 * then exited with code 0, Keboola marked the job successful, and the user got no signal that no
 * report data was downloaded.
 *
 * This class keeps the per-account tolerance and adds one rule for the whole run: if every
 * processed account failed, the run failed. Component turns that into a UserException.
 */
class ExtractionStats
{
    private const LOGGED_FAILURES_LIMIT = 3;

    private int $processedCustomersCount = 0;

    /** @var array<int, string> */
    private array $failures = [];

    public function customerSucceeded(): void
    {
        $this->processedCustomersCount++;
    }

    public function customerFailed(string $customerName, string $customerId, string $errorMessage): void
    {
        $this->processedCustomersCount++;
        $this->failures[] = sprintf('"%s" (ID "%s"): %s', $customerName, $customerId, $errorMessage);
    }

    public function getProcessedCustomersCount(): int
    {
        return $this->processedCustomersCount;
    }

    public function getFailedCustomersCount(): int
    {
        return count($this->failures);
    }

    /**
     * True only when the run processed at least one account and every one of them failed.
     *
     * A run that processed no account at all is not a failure here. The component already fails
     * earlier if the configured customer ID cannot be read.
     */
    public function everyProcessedCustomerFailed(): bool
    {
        return $this->processedCustomersCount > 0
            && count($this->failures) === $this->processedCustomersCount;
    }

    public function getEveryCustomerFailedMessage(): string
    {
        return sprintf(
            'The extraction failed for all %d processed Google Ads %s, so no report data was '
            . 'downloaded. Fix the errors listed above and run the extraction again. %s: %s',
            $this->processedCustomersCount,
            $this->processedCustomersCount === 1 ? 'account' : 'accounts',
            count($this->failures) > self::LOGGED_FAILURES_LIMIT
                ? sprintf('First %d errors', self::LOGGED_FAILURES_LIMIT)
                : 'Errors',
            implode(' ', array_slice($this->failures, 0, self::LOGGED_FAILURES_LIMIT)),
        );
    }
}
