<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use ArrayIterator;
use Google\Ads\GoogleAds\Lib\V21\GoogleAdsClient;
use Google\Ads\GoogleAds\V21\Resources\CustomerClient;
use Google\Ads\GoogleAds\V21\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V21\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V21\Services\SearchGoogleAdsResponse;
use Google\ApiCore\ApiException;
use Google\ApiCore\Page;
use Google\ApiCore\PagedListResponse;
use Google\Protobuf\FieldMask;
use Google\Rpc\Code;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\GoogleAds\Configuration\Config;
use Keboola\GoogleAds\Configuration\ConfigDefinition;
use Keboola\GoogleAds\Extractor;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Retry\BackOff\NoBackOffPolicy;
use Throwable;

/**
 * Covers the bounded retry around the customers search call. A transient HTTP 5xx from
 * Google (raised while the client fetches an access token, which it does from inside the
 * call whenever the cached one expired) used to escape as an unhandled ServerException and
 * fail the job with an opaque internal error.
 *
 * The tests inject a NoBackOffPolicy so they assert the retry wiring without sleeping.
 */
class ExtractorRetryTest extends TestCase
{
    private const ROOT_CUSTOMER_ID = '1234567890';

    private const CUSTOMER_ID = '9876543210';

    private Temp $temp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp = new Temp('ex-google-ads-retry');
    }

    public function testTransientServerErrorIsRetriedAndThenRethrown(): void
    {
        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::exactly(Config::RETRY_ATTEMPTS))
            ->method('search')
            ->willThrowException($this->createServerException());

        $extractor = $this->createExtractor($serviceClient);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('503 Service Unavailable');

        $extractor->extract(self::ROOT_CUSTOMER_ID);
    }

    public function testTransientConnectErrorIsRetriedAndThenRethrown(): void
    {
        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::exactly(Config::RETRY_ATTEMPTS))
            ->method('search')
            ->willThrowException(new ConnectException(
                'cURL error 7: Failed to connect to oauth2.googleapis.com',
                new Request('POST', 'https://oauth2.googleapis.com/token'),
            ));

        $extractor = $this->createExtractor($serviceClient);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Failed to connect');

        $extractor->extract(self::ROOT_CUSTOMER_ID);
    }

    /**
     * The behaviour the fix exists for: one transient 5xx, then the same call succeeds and
     * the extraction carries on exactly as it would have without the blip.
     */
    public function testTransientServerErrorIsRetriedAndThenSucceeds(): void
    {
        $response = $this->createEmptySearchResponse();
        $attempt = 0;

        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback(function () use (&$attempt, $response): PagedListResponse {
                $attempt++;
                if ($attempt === 1) {
                    throw $this->createServerException();
                }
                return $response;
            });

        $extractor = $this->createExtractor($serviceClient);

        // No customer rows come back, so nothing is downloaded and no exception escapes.
        self::assertSame([], $extractor->extract(self::ROOT_CUSTOMER_ID));
    }

    /**
     * The campaigns call site retries ServerException, which nothing else catches.
     */
    public function testServerErrorAtCampaignsIsRetriedAndThenRethrown(): void
    {
        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::exactly(1 + Config::RETRY_ATTEMPTS))
            ->method('search')
            ->willReturnCallback($this->customersThenFailing($this->createServerException()));

        $extractor = $this->createExtractor($serviceClient);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('503 Service Unavailable');

        $extractor->extract(self::ROOT_CUSTOMER_ID);
    }

    /**
     * Guards the deliberate asymmetry between the two whitelists. extract() already catches a
     * ConnectException around the campaigns block, logs it and moves on to the next customer.
     * Retrying it there would change which accounts get extracted, so the campaigns site must
     * NOT retry it: exactly one campaigns attempt, and the customer is still marked downloaded.
     *
     * If the campaigns whitelist is ever widened to TRANSIENT_RETRY_EXCEPTIONS, the call count
     * below becomes 1 + Config::RETRY_ATTEMPTS and this test fails - which is the point.
     */
    public function testConnectErrorAtCampaignsIsAttemptedOnceAndTheAccountIsSkipped(): void
    {
        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback($this->customersThenFailing(new ConnectException(
                'cURL error 7: Failed to connect to googleads.googleapis.com',
                new Request('POST', 'https://googleads.googleapis.com/'),
            )));

        $extractor = $this->createExtractor($serviceClient);

        self::assertSame([self::CUSTOMER_ID], $extractor->extract(self::ROOT_CUSTOMER_ID));
    }

    public function testDeterministicApiErrorIsNotRetried(): void
    {
        $serviceClient = $this->createMock(GoogleAdsServiceClient::class);
        $serviceClient
            ->expects(self::once())
            ->method('search')
            ->willThrowException(new ApiException(
                'The caller does not have permission',
                Code::PERMISSION_DENIED,
                'PERMISSION_DENIED',
            ));

        $extractor = $this->createExtractor($serviceClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('The caller does not have permission');

        $extractor->extract(self::ROOT_CUSTOMER_ID);
    }

    private function createServerException(): ServerException
    {
        return new ServerException(
            'Server error: `POST https://oauth2.googleapis.com/token` resulted in a '
            . '`503 Service Unavailable` response: {"error": "internal_failure"}',
            new Request('POST', 'https://oauth2.googleapis.com/token'),
            new Response(503),
        );
    }

    /**
     * A search response carrying no field mask and no rows. getColumnsFromSearch() returns
     * an empty column list for it and iterating yields nothing, which keeps the successful
     * path in this test to the part being asserted: that the retry handed the value back.
     */
    private function createEmptySearchResponse(): PagedListResponse
    {
        return $this->createPagedListResponse(new SearchGoogleAdsResponse(), []);
    }

    /**
     * @param array<int, GoogleAdsRow> $rows
     */
    private function createPagedListResponse(SearchGoogleAdsResponse $response, array $rows): PagedListResponse
    {
        $page = $this->createMock(Page::class);
        $page->method('getResponseObject')->willReturn($response);
        $page->method('getIterator')->willReturn(new ArrayIterator($rows));
        $page->method('hasNextPage')->willReturn(false);
        $page->method('getPageElementCount')->willReturn(count($rows));

        $search = $this->createMock(PagedListResponse::class);
        $search->method('getPage')->willReturn($page);
        $search->method('iterateAllElements')->willReturn(new ArrayIterator($rows));

        return $search;
    }

    /**
     * First search() call returns one non-manager customer, so extract() enters its loop body
     * and reaches getAndSaveCampaigns(); every later call throws $failure.
     *
     * @return callable(): PagedListResponse
     */
    private function customersThenFailing(Throwable $failure): callable
    {
        $customers = $this->createCustomersSearchResponse();
        $attempt = 0;

        return function () use (&$attempt, $customers, $failure): PagedListResponse {
            $attempt++;
            if ($attempt === 1) {
                return $customers;
            }
            throw $failure;
        };
    }

    /**
     * A customers page carrying a single enabled (non-manager) customer.
     */
    private function createCustomersSearchResponse(): PagedListResponse
    {
        $fieldMask = new FieldMask();
        $fieldMask->setPaths(['customer_client.id', 'customer_client.descriptive_name']);

        $response = new SearchGoogleAdsResponse();
        $response->setFieldMask($fieldMask);

        $customerClient = new CustomerClient();
        $customerClient->setId((int) self::CUSTOMER_ID);
        $customerClient->setManager(false);
        $customerClient->setDescriptiveName('Test account');

        $row = new GoogleAdsRow();
        $row->setCustomerClient($customerClient);

        return $this->createPagedListResponse($response, [$row]);
    }

    private function createExtractor(GoogleAdsServiceClient $serviceClient): Extractor
    {
        $googleAdsClient = $this->createMock(GoogleAdsClient::class);
        $googleAdsClient
            ->method('getGoogleAdsServiceClient')
            ->willReturn($serviceClient);

        $config = new Config(
            [
                'parameters' => [
                    'customerId' => [self::ROOT_CUSTOMER_ID],
                    'name' => 'testName',
                    'query' => 'SELECT campaign.id FROM campaign',
                    'primary' => [],
                    'onlyEnabledCustomers' => true,
                ],
                'image_parameters' => [
                    '#developer_token' => 'imageToken',
                ],
            ],
            new ConfigDefinition(),
        );

        $dataDir = $this->temp->getTmpFolder();
        mkdir($dataDir . '/out/tables', 0777, true);

        return new Extractor(
            $googleAdsClient,
            $config,
            new NullLogger(),
            new ManifestManager($dataDir),
            $dataDir,
            [],
            new NoBackOffPolicy(),
        );
    }
}
