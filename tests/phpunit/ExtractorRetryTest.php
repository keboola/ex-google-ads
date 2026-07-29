<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use ArrayIterator;
use Google\Ads\GoogleAds\Lib\V21\GoogleAdsClient;
use Google\Ads\GoogleAds\V21\Services\Client\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V21\Services\SearchGoogleAdsResponse;
use Google\ApiCore\ApiException;
use Google\ApiCore\Page;
use Google\ApiCore\PagedListResponse;
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

        $extractor->extract('1234567890');
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

        $extractor->extract('1234567890');
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
        self::assertSame([], $extractor->extract('1234567890'));
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

        $extractor->extract('1234567890');
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
        $page = $this->createMock(Page::class);
        $page->method('getResponseObject')->willReturn(new SearchGoogleAdsResponse());
        $page->method('getIterator')->willReturn(new ArrayIterator([]));
        $page->method('hasNextPage')->willReturn(false);
        $page->method('getPageElementCount')->willReturn(0);

        $search = $this->createMock(PagedListResponse::class);
        $search->method('getPage')->willReturn($page);
        $search->method('iterateAllElements')->willReturn(new ArrayIterator([]));

        return $search;
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
                    'customerId' => ['1234567890'],
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
