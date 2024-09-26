<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Google\Ads\GoogleAds\Lib\Configuration;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClientBuilder;
use Google\Auth\Credentials\UserRefreshCredentials;
use Keboola\GoogleAds\GetAccountHierarchy;
use PHPUnit\Framework\TestCase;

class GetAccountHierarchyTest extends TestCase
{
    private GoogleAdsClient $googleAdsClient;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var array{refresh_token: string} $credentialsData */
        $credentialsData = json_decode((string) getenv('API_DATA'), true);

        $oauthConfiguration = new Configuration([
            'OAUTH2' => [
                'clientId' => (string) getenv('CLIENT_ID'),
                'clientSecret' => (string) getenv('CLIENT_SECRET'),
                'refreshToken' => $credentialsData['refresh_token'],
            ],
        ]);

        /** @var UserRefreshCredentials $oauth */
        $oauth = (new OAuth2TokenBuilder())->from($oauthConfiguration)->build();

        $this->googleAdsClient = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($oauth)
            ->withDeveloperToken((string) getenv('DEVELOPER_TOKEN'))
            ->build();
    }

    public function testRunWithChildren(): void
    {
        $accountHierarchy = new GetAccountHierarchy($this->googleAdsClient, true);

        $result = $accountHierarchy->run();

        self::assertGreaterThanOrEqual(1, count($result));
        self::assertArrayHasKey(
            (string) getenv('CUSTOMER_ID_MANAGER_WITH_SUBACCOUNTS'),
            $result,
            (string) json_encode($result),
        );

        $customer = $result[(int) getenv('CUSTOMER_ID_MANAGER_WITH_SUBACCOUNTS')];

        // check customer info
        self::assertArrayHasKey('info', $customer);
        self::assertArrayHasKey('id', $customer['info']);
        self::assertArrayHasKey('descriptiveName', $customer['info']);
        self::assertArrayHasKey('resourceName', $customer['info']);
        self::assertArrayHasKey('level', $customer['info']);
        self::assertEquals(0, $customer['info']['level']);

        // check customer sub-accounts
        self::assertArrayHasKey('children', $customer);
        self::assertGreaterThanOrEqual(1, count($customer['children']));

        $firstChild = $customer['children'][0];
        self::assertArrayHasKey('id', $firstChild['info']);
        self::assertArrayHasKey('descriptiveName', $firstChild['info']);
        self::assertArrayHasKey('resourceName', $firstChild['info']);
        self::assertArrayHasKey('level', $firstChild['info']);
        self::assertArrayHasKey('children', $firstChild);
        self::assertEquals(1, $firstChild['info']['level']);
    }

    public function testRunWithoutChildren(): void
    {
        $accountHierarchy = new GetAccountHierarchy($this->googleAdsClient);

        $result = $accountHierarchy->run();

        self::assertGreaterThanOrEqual(1, count($result));
        self::assertArrayHasKey(
            (string) getenv('CUSTOMER_ID_MANAGER_WITH_SUBACCOUNTS'),
            $result,
            (string) json_encode($result),
        );

        $customer = $result[(int) getenv('CUSTOMER_ID_MANAGER_WITH_SUBACCOUNTS')];

        // check customer info
        self::assertArrayHasKey('info', $customer);
        self::assertArrayHasKey('id', $customer['info']);
        self::assertArrayHasKey('descriptiveName', $customer['info']);
        self::assertArrayHasKey('resourceName', $customer['info']);
        self::assertArrayHasKey('level', $customer['info']);
        self::assertEquals(0, $customer['info']['level']);

        // check customer sub-accounts
        self::assertArrayHasKey('children', $customer);
        self::assertCount(0, $customer['children']);
    }
}
