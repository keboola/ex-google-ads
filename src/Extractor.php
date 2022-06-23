<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

use Generator;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsClient;
use Google\Ads\GoogleAds\V10\Resources\Customer;
use Google\Ads\GoogleAds\V10\Resources\CustomerClient;
use Google\Ads\GoogleAds\V10\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V10\Services\SearchGoogleAdsResponse;
use Google\ApiCore\ApiException;
use Google\ApiCore\PagedListResponse;
use Google\Protobuf\Internal\Message;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTableManifestOptions;
use Keboola\Component\UserException;
use Keboola\Csv\CsvWriter;
use Keboola\Csv\Exception;
use Keboola\GoogleAds\Configuration\Config;
use Psr\Log\LoggerInterface;
use Retry\BackOff\ExponentialBackOffPolicy;
use Retry\Policy\SimpleRetryPolicy;
use Retry\RetryProxy;
use Symfony\Component\Filesystem\Filesystem;

class Extractor
{
    private const REPORT_PAGE_SIZE = 1000;

    private const CUSTOMER_TABLE = 'customer';

    private const CAMPAIGN_TABLE = 'campaign';

    private const USER_TABLES_PRIMARY_KEYS = [
        self::CUSTOMER_TABLE => ['id'],
        self::CAMPAIGN_TABLE => ['customerId', 'id'],
    ];

    private GoogleAdsClient $googleAdsClient;

    private Config $config;

    private LoggerInterface $logger;

    private ManifestManager $manifestManager;

    private string $dataDir;

    /** @var string[] */
    private array $customersIdDownloaded;

    /**
     * @param string[] $customersIdDownloaded
     */
    public function __construct(
        GoogleAdsClient $googleAdsClient,
        Config $config,
        LoggerInterface $logger,
        ManifestManager $manifestManager,
        string $dataDir,
        array $customersIdDownloaded
    ) {
        $this->googleAdsClient = $googleAdsClient;
        $this->config = $config;
        $this->logger = $logger;
        $this->manifestManager = $manifestManager;
        $this->dataDir = $dataDir;
        $this->customersIdDownloaded = $customersIdDownloaded;
    }

    /**
     * @return string[]
     */
    public function extract(string $rootCustomerId): array
    {
        /** @var Customer $customer */
        foreach ($this->getAndSaveCustomers($rootCustomerId) as $customer) {
            $customerId = (string) $customer->getId();
            $this->logger->info(sprintf('Extraction data of customer "%s".', $customer->getDescriptiveName()));

            $this->logger->info('Downloading campaigns.');
            $this->getAndSaveCampaigns($customerId);

            // Download Report
            $this->logger->info('Downloading query report.');
            try {
                $this->getRetryProxy()->call(function () use ($customerId): void {
                    $tableName = sprintf('report-%s', $this->config->getName());
                    $filePath = sprintf(
                        '%s/out/tables/%s.csv',
                        $this->dataDir,
                        $tableName
                    );

                    $fs = new Filesystem();
                    if ($fs->exists($filePath)) {
                        $fs->remove($filePath);
                    }

                    $this->getReport(
                        $customerId,
                        $this->config->getQuery(),
                        $tableName
                    );
                });
            } catch (ApiException $e) {
                $this->logger->error(sprintf(
                    'Getting report for client "%s" failed: "%s".',
                    $customer->getDescriptiveName(),
                    $e->getMessage()
                ));
            }
            $this->customersIdDownloaded[] = $customerId;
        }
        return $this->customersIdDownloaded;
    }

    private function getAndSaveCustomers(string $customerId): Generator
    {
        $query = [];
        $query[] = 'SELECT '
            . 'customer_client.id, '
            . 'customer_client.manager, '
            . 'customer_client.descriptive_name, '
            . 'customer_client.currency_code, '
            . 'customer_client.time_zone';

        $query[] = ' FROM customer_client';
        if ($this->config->onlyEnabledCustomers()) {
            $query[] = ' WHERE customer_client.status = ENABLED';
        }
        $query[] = ' ORDER BY customer_client.id';

        $search = $this->googleAdsClient->getGoogleAdsServiceClient()->search(
            $customerId,
            implode(' ', $query)
        );

        $listColumns = $this->getColumnsFromSearch($search, true);
        unset($listColumns['manager']);

        $csvCustomer = $this->openCsvFile(sprintf(
            '%s/out/tables/%s.csv',
            $this->dataDir,
            self::CUSTOMER_TABLE
        ));

        // Create manifest for Customer
        $manifestOptions = new OutTableManifestOptions();
        $manifestOptions
            ->setIncremental(true)
            ->setPrimaryKeyColumns(self::USER_TABLES_PRIMARY_KEYS[self::CUSTOMER_TABLE])
            ->setColumns(array_values($listColumns));

        $this->manifestManager->writeTableManifest(
            sprintf('%s.csv', self::CUSTOMER_TABLE),
            $manifestOptions
        );

        foreach ($search->iterateAllElements() as $result) {
            /** @var GoogleAdsRow $result */
            if (!($result->getCustomerClient() instanceof CustomerClient)) {
                continue;
            }

            if ($result->getCustomerClient()->getManager()) {
                continue;
            }

            if (in_array($customerId, $this->customersIdDownloaded)) {
                $this->logger->info(sprintf(
                    'Customer "%s" already downloaded.',
                    $result->getCustomerClient()->getDescriptiveName()
                ));
                continue;
            }

            $parsedCustomer = $this->parseResponse($result->getCustomerClient(), $listColumns);
            $csvCustomer->writeRow($parsedCustomer);
            yield $result->getCustomerClient();
        }
    }

    private function getAndSaveCampaigns(string $customerId): void
    {
        $csvCampaign = $this->openCsvFile(sprintf(
            '%s/out/tables/%s.csv',
            $this->dataDir,
            self::CAMPAIGN_TABLE
        ));

        $query = [];
        $query[] = 'SELECT '
            . 'campaign.id, '
            . 'campaign.name, '
            . 'campaign.status, '
            . 'campaign.serving_status, '
            . 'campaign.ad_serving_optimization_status, '
            . 'campaign.advertising_channel_type, '
            . 'campaign.start_date, '
            . 'campaign.end_date';

        $query[] = 'FROM campaign';
        $where = [];
        if ($this->config->onlyEnabledCustomers()) {
            $where[] = 'campaign.status = ENABLED';
        }
        if ($this->config->getSince() && $this->config->getUntil()) {
            $where[] = sprintf(
                'segments.date BETWEEN "%s" AND "%s"',
                $this->config->getSince(),
                $this->config->getUntil()
            );
        }
        $query[] = 'WHERE ' . implode(' AND ', $where);
        $query[] = 'ORDER BY campaign.id';

        $search = $this->googleAdsClient->getGoogleAdsServiceClient()->search(
            $customerId,
            implode(' ', $query)
        );

        $listColumns = $this->getColumnsFromSearch($search, true);

        foreach ($search->iterateAllElements() as $result) {
            /** @var GoogleAdsRow $result */
            /** @var Message $campaign */
            $campaign = $result->getCampaign();
            $parsedCampaign = $this->parseResponse($campaign, $listColumns);
            $csvCampaign->writeRow(array_merge(
                ['customerId' => $customerId],
                $parsedCampaign
            ));
        }

        // Create manifest for Campaigns
        $manifestOptions = new OutTableManifestOptions();
        $manifestOptions
            ->setIncremental(true)
            ->setPrimaryKeyColumns(self::USER_TABLES_PRIMARY_KEYS[self::CAMPAIGN_TABLE])
            ->setColumns(array_values(['customerId'] + $listColumns));

        $this->manifestManager->writeTableManifest(
            sprintf('%s.csv', self::CAMPAIGN_TABLE),
            $manifestOptions
        );
    }

    private function getReport(string $customerId, string $query, string $tableName): void
    {
        if ($this->config->getSince() && $this->config->getUntil()) {
            $query .= sprintf(
                ' WHERE segments.date BETWEEN "%s" AND "%s"',
                $this->config->getSince(),
                $this->config->getUntil()
            );
        }

        $search = $this->googleAdsClient
            ->getGoogleAdsServiceClient()
            ->search(
                $customerId,
                $query,
                ['pageSize' => self::REPORT_PAGE_SIZE]
            );

        $page = $search->getPage();
        if ($page->getPageElementCount() === 0) {
            return;
        }

        $csv = $this->openCsvFile(sprintf(
            '%s/out/tables/%s.csv',
            $this->dataDir,
            $tableName
        ));

        $listColumns = $this->getColumnsFromSearch($search);

        $hasNextPage = true;
        $isPrimaryKeysValidated = false;
        while ($hasNextPage) {
            /** @var SearchGoogleAdsResponse $response */
            $response = $page->getResponseObject();

            /** @var GoogleAdsRow $result */
            foreach ($response->getResults() as $result) {
                $data = $this->parseResponse($result, $listColumns);
                if (!$isPrimaryKeysValidated) {
                    $this->validatePrimaryKeys($listColumns, $this->config->getPrimaryKeys());
                    $isPrimaryKeysValidated = true;
                }
                $csv->writeRow($data);
            }

            $hasNextPage = $page->hasNextPage();
            if ($hasNextPage) {
                $page = $page->getNextPage();
            }
        }

        // Create manifest for Report
        $manifestOptions = new OutTableManifestOptions();
        $manifestOptions
            ->setIncremental(true)
            ->setPrimaryKeyColumns($this->config->getPrimaryKeys())
            ->setColumns(array_values($listColumns));

        $this->manifestManager->writeTableManifest(
            sprintf('%s.csv', $tableName),
            $manifestOptions
        );
    }

    /**
     * @param array<string, string> $listColumns
     * @return array<string, string>
     */
    private function parseResponse(Message $result, array $listColumns): array
    {
        $json = $result->serializeToJsonString();
        $data = json_decode($json, true);
        return $this->processResultRow($data, $listColumns);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $listColumns
     * @return array<string, string>
     */
    private function processResultRow(array $data, array $listColumns): array
    {
        $output = [];
        foreach ($listColumns as $columnKey => $columnName) {
            $columnData = $data;
            foreach (explode('.', $columnKey) as $key) {
                if (!array_key_exists($key, $columnData)) {
                    $output[$columnName] = null;
                    continue 2;
                }
                $columnData = $columnData[$key];
            }
            if (is_array($columnData)) {
                $columnData = json_encode($columnData);
            }
            $output[$columnName] = $columnData;
        }
        return $output;
    }

    /**
     * @param array<string, string> $columns
     * @param array<int, string> $primaryKeys
     */
    private function validatePrimaryKeys(array $columns, array $primaryKeys): void
    {
        $invalidPrimaryKeys = [];
        foreach ($primaryKeys as $primaryKey) {
            if (!in_array($primaryKey, $columns)) {
                $invalidPrimaryKeys[] = $primaryKey;
            }
        }
        if ($invalidPrimaryKeys) {
            throw new UserException(sprintf(
                'Primary keys "%s" are not valid. Expected keys: "%s"',
                implode(', ', $invalidPrimaryKeys),
                implode(', ', $columns)
            ));
        }
    }

    /**
     * @return array<string,string>
     */
    private function getColumnsFromSearch(PagedListResponse $search, bool $skipFirstColumnKey = false): array
    {
        $listColumns = [];

        /** @var SearchGoogleAdsResponse $response */
        $response = $search->getPage()->getResponseObject();

        $fieldMask = $response->getFieldMask();
        if (!$fieldMask) {
            return [];
        }
        $fieldMaskPaths = $fieldMask->getPaths();
        $iterator = $fieldMaskPaths->getIterator();
        while ($iterator->valid()) {
            $column = (string) $iterator->current();
            if ($skipFirstColumnKey) {
                $column = explode('.', $column);
                array_shift($column);
                $column = implode('.', $column);
            }
            $columnKey = lcfirst(str_replace('_', '', ucwords($column, '_')));
            $columnValue = lcfirst(str_replace(['.', '_'], '', ucwords($column, '._')));

            $listColumns[$columnKey] = $columnValue;
            $iterator->next();
        }

        return $listColumns;
    }

    private function openCsvFile(string $fileName): CsvWriter
    {
        $filePointer = @fopen($fileName, 'a');
        if (!$filePointer) {
            $message = !is_null(error_get_last()) ? error_get_last()['message'] : '';
            throw new Exception(
                "Cannot open file {$fileName} " . $message,
                Exception::FILE_NOT_EXISTS
            );
        }
        return new CsvWriter($filePointer);
    }

    private function getRetryProxy(): RetryProxy
    {
        $policy = new SimpleRetryPolicy(
            Config::RETRY_ATTEMPTS,
            ['Exception', 'ErrorExceptions', 'ApiException']
        );
        $backoff = new ExponentialBackOffPolicy();
        $retryProxy = new RetryProxy($policy, $backoff);

        return $retryProxy;
    }
}
