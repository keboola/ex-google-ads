<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Google\Ads\GoogleAds\Lib\V21\GoogleAdsClient;
use Google\Ads\GoogleAds\V21\Services\Client\GoogleAdsServiceClient;
use Google\ApiCore\ApiException;
use Google\Rpc\Code;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\GoogleAds\Configuration\Config;
use Keboola\GoogleAds\Configuration\ConfigDefinition;
use Keboola\GoogleAds\Extractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Covers the bounded retry around the customers search call. A transient HTTP 5xx from
 * Google (typically raised while the client refreshes the OAuth2 token, which happens on
 * the first API call of a run) used to escape as an unhandled ServerException and fail the
 * job with an opaque internal error.
 */
class ExtractorRetryTest extends TestCase
{
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

        $dataDir = sys_get_temp_dir();

        return new Extractor(
            $googleAdsClient,
            $config,
            new NullLogger(),
            new ManifestManager($dataDir),
            $dataDir,
            [],
        );
    }
}
