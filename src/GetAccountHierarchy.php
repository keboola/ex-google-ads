<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

use Google\Ads\GoogleAds\Lib\V10\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsException;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsServerStreamDecorator;
use Google\Ads\GoogleAds\V10\Errors\GoogleAdsError;
use Google\Ads\GoogleAds\V10\Resources\CustomerClient;
use Google\Ads\GoogleAds\V10\Services\CustomerServiceClient;
use Google\Ads\GoogleAds\V10\Services\GoogleAdsRow;
use Google\ApiCore\ApiException;
use Keboola\Component\UserException;

class GetAccountHierarchy
{
    private GoogleAdsClient $googleAdsClient;

    /**
     * @var CustomerClient[]
     */
    private static array $rootCustomerClients = [];

    public function __construct(GoogleAdsClient $googleAdsClient)
    {
        $this->googleAdsClient = $googleAdsClient;
    }

    /**
     * @return array<int|string, mixed>|array
     */
    public function run(): array
    {
        $allHierarchies = [];
        try {
            $rootCustomerIds = self::getAccessibleCustomers($this->googleAdsClient);

            foreach ($rootCustomerIds as $rootCustomerId) {
                try {
                    $customerClientToHierarchy = self::createCustomerClientToHierarchy($rootCustomerId);
                } catch (ApiException $exception) {
                    continue;
                }
                if (!is_null($customerClientToHierarchy)) {
                    $allHierarchies += $customerClientToHierarchy;
                }
            }
        } catch (GoogleAdsException $googleAdsException) {
            $messages = [];
            foreach ($googleAdsException->getGoogleAdsFailure()->getErrors() as $error) {
                /** @var GoogleAdsError $error */
                $messages[] = $error->getMessage();
            }
            throw new UserException(implode("\n", $messages));
        } catch (ApiException $apiException) {
            throw new UserException(sprintf(
                "ApiException was thrown with message '%s'.",
                $apiException->getMessage()
            ));
        }

        $result = [];
        foreach ($allHierarchies as $rootCustomerId => $customerIdsToChildAccounts) {
            $result[$rootCustomerId] =
                self::buildAccountHierarchy(self::$rootCustomerClients[$rootCustomerId], $customerIdsToChildAccounts);
        }
        return $result;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function createCustomerClientToHierarchy(int $rootCustomerId): ?array
    {
        $googleAdsClient = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($this->googleAdsClient->getOAuth2Credential())
            ->withDeveloperToken($this->googleAdsClient->getDeveloperToken())
            ->withLoginCustomerId($loginCustomerId ?? $rootCustomerId)
            ->build();

        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();

        $query = 'SELECT customer_client.client_customer, customer_client.level,'
            . ' customer_client.manager, customer_client.descriptive_name,'
            . ' customer_client.id FROM customer_client'
            . ' WHERE customer_client.level <= 1 AND customer_client.status = ENABLED';

        $rootCustomerClient = null;
        $managerCustomerIdsToSearch = [$rootCustomerId];

        $customerIdsToChildAccounts = [];

        while (!empty($managerCustomerIdsToSearch)) {
            $customerIdToSearch = array_shift($managerCustomerIdsToSearch);

            /** @var GoogleAdsServerStreamDecorator $stream */
            $stream = $googleAdsServiceClient->searchStream(
                (string) $customerIdToSearch,
                $query
            );

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var GoogleAdsRow $googleAdsRow */
                $customerClient = $googleAdsRow->getCustomerClient();

                if (is_null($customerClient)) {
                    continue;
                }

                if ($customerClient->getId() === $rootCustomerId) {
                    $rootCustomerClient = $customerClient;
                    self::$rootCustomerClients[$rootCustomerId] = $rootCustomerClient;
                }

                if ($customerClient->getId() === $customerIdToSearch) {
                    continue;
                }

                $customerIdsToChildAccounts[$customerIdToSearch][] = $customerClient;

                if ($customerClient->getManager()) {
                    $alreadyVisited = array_key_exists(
                        $customerClient->getId(),
                        $customerIdsToChildAccounts
                    );
                    if (!$alreadyVisited && $customerClient->getLevel() === 1) {
                        array_push($managerCustomerIdsToSearch, $customerClient->getId());
                    }
                }
            }
        }

        return is_null($rootCustomerClient) ? null : [$rootCustomerClient->getId() => $customerIdsToChildAccounts];
    }

    /**
     * @return int[]
     */
    private static function getAccessibleCustomers(GoogleAdsClient $googleAdsClient): array
    {
        $customerServiceClient = $googleAdsClient->getCustomerServiceClient();
        $accessibleCustomers = $customerServiceClient->listAccessibleCustomers();

        $accessibleCustomerIds = [];
        foreach ($accessibleCustomers->getResourceNames() as $customerResourceName) {
            $customer = CustomerServiceClient::parseName($customerResourceName)['customer_id'];
            $accessibleCustomerIds[] = intval($customer);
        }

        return $accessibleCustomerIds;
    }


    /**
     * @param array<string, mixed> $customerIdsToChildAccounts
     * @return array<string, mixed>
     */
    private static function buildAccountHierarchy(
        CustomerClient $customerClient,
        array $customerIdsToChildAccounts
    ): array {
        $customerId = $customerClient->getId();
        $customer = [
            'info' => json_decode($customerClient->serializeToJsonString(), true),
            'children' => [],
        ];

        // Recursively call this function for all child accounts of $customerClient.
        if (array_key_exists($customerId, $customerIdsToChildAccounts)) {
            foreach ($customerIdsToChildAccounts[$customerId] as $childAccount) {
                $customer['children'][] = self::buildAccountHierarchy($childAccount, $customerIdsToChildAccounts);
            }
        }

        return $customer;
    }
}
