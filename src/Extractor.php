<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

use Generator;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsClient;
use Google\Ads\GoogleAds\V10\Resources\Customer;
use Google\Ads\GoogleAds\V10\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V10\Services\SearchGoogleAdsResponse;
use Google\ApiCore\ApiException;
use Google\Protobuf\Internal\Message;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTableManifestOptions;
use Keboola\Component\UserException;
use Keboola\Csv\CsvWriter;
use Keboola\GoogleAds\Configuration\Config;
use Psr\Log\LoggerInterface;

class Extractor
{
    private const REPORT_PAGE_SIZE = 1000;

    private const CUSTOMER_TABLE = 'customer';

    private const CAMPAIGN_TABLE = 'campaign';

    private const USER_TABLES_STRUCTURE = [
        self::CUSTOMER_TABLE => [
            'primaryKeys' => ['id'],
            'columns' => [
                'id',
                'descriptiveName',
                'currencyCode',
                'timeZone',
            ],
        ],
        self::CAMPAIGN_TABLE => [
            'primaryKeys' => ['customerId', 'id'],
            'columns' => [
                'customerId',
                'id',
                'name',
                'status',
                'servingStatus',
                'adServingOptimizationStatus',
                'advertisingChannelType',
                'startDate',
                'endDate',
            ],
        ],
    ];

    private const SKIP_RESOURCE_ITEM = [
        'resourceName',
    ];

    private GoogleAdsClient $googleAdsClient;

    private Config $config;

    private LoggerInterface $logger;

    private ManifestManager $manifestManager;

    private string $dataDir;

    public function __construct(
        GoogleAdsClient $googleAdsClient,
        Config $config,
        LoggerInterface $logger,
        ManifestManager $manifestManager,
        string $dataDir
    ) {
        $this->googleAdsClient = $googleAdsClient;
        $this->config = $config;
        $this->logger = $logger;
        $this->manifestManager = $manifestManager;
        $this->dataDir = $dataDir;
    }

    public function extract(): void
    {
        $csvCustomer = new CsvWriter(sprintf('%s/out/tables/%s.csv', $this->dataDir, self::CUSTOMER_TABLE));
        $csvCampaign = new CsvWriter(sprintf('%s/out/tables/%s.csv', $this->dataDir, self::CAMPAIGN_TABLE));

        $reportTableName = null;
        $reportColumns = [];
        /** @var Customer $customer */
        foreach ($this->getCustomers() as $customer) {
            $this->logger->info(sprintf('Extraction data of customer "%s".', $customer->getDescriptiveName()));
            $parsedCustomer = $this->parseResponse($customer);
            $csvCustomer->writeRow($parsedCustomer);

            $this->logger->info('Downloading campaigns.');
            foreach ($this->getCampaigns($parsedCustomer['id']) as $campaign) {
                $parsedCampaign = $this->parseResponse($campaign);
                $csvCampaign->writeRow(array_merge(
                    ['customerId' => $parsedCustomer['id']],
                    $parsedCampaign
                ));
            }

            // Download Report
            $this->logger->info('Downloading query report.');
            try {
                $reportTableName = sprintf('report-%s', $this->config->getName());
                $reportColumns = $this->getReport(
                    $parsedCustomer['id'],
                    $this->config->getQuery(),
                    $reportTableName
                );
            } catch (ApiException $e) {
                $this->logger->error(sprintf(
                    'Getting report for client "%s" failed: "%s".',
                    $customer->getDescriptiveName(),
                    $e->getMessage()
                ));
            }
        }

        // Create manifest for Customer
        $manifestOptions = new OutTableManifestOptions();
        $manifestOptions
            ->setIncremental(true)
            ->setPrimaryKeyColumns(self::USER_TABLES_STRUCTURE[self::CUSTOMER_TABLE]['primaryKeys'])
            ->setColumns(self::USER_TABLES_STRUCTURE[self::CUSTOMER_TABLE]['columns']);

        $this->manifestManager->writeTableManifest(
            sprintf('%s.csv', self::CUSTOMER_TABLE),
            $manifestOptions
        );

        // Create manifest for Campaigns
        $manifestOptions = new OutTableManifestOptions();
        $manifestOptions
            ->setIncremental(true)
            ->setPrimaryKeyColumns(self::USER_TABLES_STRUCTURE[self::CAMPAIGN_TABLE]['primaryKeys'])
            ->setColumns(self::USER_TABLES_STRUCTURE[self::CAMPAIGN_TABLE]['columns']);

        $this->manifestManager->writeTableManifest(
            sprintf('%s.csv', self::CAMPAIGN_TABLE),
            $manifestOptions
        );

        if ($reportTableName && $reportColumns) {
            // Create manifest for Report
            $manifestOptions = new OutTableManifestOptions();
            $manifestOptions
                ->setIncremental(true)
                ->setPrimaryKeyColumns($this->config->getPrimaryKeys())
                ->setColumns($reportColumns);

            $this->manifestManager->writeTableManifest(
                sprintf('%s.csv', $reportTableName),
                $manifestOptions
            );
        }
    }

    private function getCustomers(): Generator
    {
        $query = [];
        $query[] = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone';
        $query[] = 'FROM customer';
        if ($this->config->getSince() && $this->config->getUntil()) {
            $query[] = sprintf(
                'WHERE segments.date BETWEEN "%s" AND "%s"',
                $this->config->getSince(),
                $this->config->getUntil()
            );
        }
        $query[] = 'ORDER BY customer.id';

        $search = $this->googleAdsClient->getGoogleAdsServiceClient()->search(
            $this->config->getCustomerId(),
            implode(' ', $query)
        );

        foreach ($search->iterateAllElements() as $result) {
            yield $result->getCustomer();
        }
    }

    private function getCampaigns(string $customerId): Generator
    {
        $query = [];
        $query[] = 'SELECT '
            . 'campaign.id, '
            . 'campaign.name, '
            . 'campaign.status, '
            . 'campaign.serving_status, '
            . 'campaign.start_date, '
            . 'campaign.end_date, '
            . 'campaign.ad_serving_optimization_status, '
            . 'campaign.advertising_channel_type';

        $query[] = 'FROM campaign';
        $where = [];
        $where[] = 'campaign.status = ENABLED';
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

        foreach ($search->iterateAllElements() as $result) {
            yield $result->getCampaign();
        }
    }

    /**
     * @return array<int, string>
     */
    private function getReport(string $customerId, string $query, string $tableName): array
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
            return [];
        }

        $csv = new CsvWriter(sprintf(
            '%s/out/tables/%s.csv',
            $this->dataDir,
            $tableName
        ));

        $listColumns = [];
        $hasNextPage = true;
        $isPrimaryKeysValidated = false;
        while ($hasNextPage) {
            /** @var SearchGoogleAdsResponse $response */
            $response = $page->getResponseObject();

            /** @var GoogleAdsRow $result */
            foreach ($response->getResults() as $result) {
                $data = $this->parseResponse($result);
                if (!$isPrimaryKeysValidated) {
                    $listColumns = array_keys($data);
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

        return $listColumns;
    }

    /**
     * @return array<string, string>
     */
    private function parseResponse(Message $result): array
    {
        $json = $result->serializeToJsonString();
        $data = json_decode($json, true);
        $output = [];
        foreach ($data as $key => $item) {
            if (in_array($key, self::SKIP_RESOURCE_ITEM)) {
                continue;
            }
            if (is_array($item)) {
                foreach ($item as $subKey => $subItem) {
                    if (in_array($subKey, self::SKIP_RESOURCE_ITEM)) {
                        continue;
                    }
                    $output[sprintf('%s%s', $key, ucfirst($subKey))] = $subItem;
                }
            } else {
                $output[(string) $key] = $item;
            }
        }
        return $output;
    }

    /**
     * @param array<int, string> $columns
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
}
