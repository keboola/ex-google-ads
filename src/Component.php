<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

use Google\Ads\GoogleAds\Lib\Configuration;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V10\GoogleAdsClientBuilder;
use Google\ApiCore\ApiException;
use GuzzleHttp\Exception\ClientException;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\GoogleAds\Configuration\Config;
use Keboola\GoogleAds\Configuration\ConfigDefinition;

class Component extends BaseComponent
{
    protected function run(): void
    {
        $oauth = (new OAuth2TokenBuilder())->from($this->getOAuthConfiguration())->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($oauth)
            ->from($this->getGoogleAdsConfiguration())
            ->build();

        $extractor = new Extractor(
            $googleAdsClient,
            $this->getConfig(),
            $this->getLogger(),
            $this->getManifestManager(),
            $this->getDataDir()
        );

        try {
            $extractor->extract();
        } catch (ClientException $e) {
            if (in_array($e->getCode(), range(400, 499))) {
                throw new UserException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (ApiException $e) {
            $message = json_decode($e->getMessage(), true);
            throw new UserException(sprintf(
                '%s: %s',
                $e->getStatus(),
                $message['message'] ?? $e->getMessage()
            ));
        }
    }

    public function getConfig(): Config
    {
        /** @var Config $config */
        $config = parent::getConfig();
        return $config;
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }

    private function getOAuthConfiguration(): Configuration
    {
        if (empty($this->getConfig()->getAuthorization()['oauth_api']['credentials'])) {
            throw new UserException('OAuth Credentials is not set.');
        }

        $credentials = $this->getConfig()->getAuthorization()['oauth_api']['credentials'];
        $credentialsData = json_decode($credentials['#data'], true);
        return new Configuration([
            'OAUTH2' => [
                'clientId' => $credentials['appKey'],
                'clientSecret' => $credentials['#appSecret'],
                'refreshToken' => $credentialsData['refresh_token'],
            ],
        ]);
    }

    private function getGoogleAdsConfiguration(): Configuration
    {
        return new Configuration([
            'GOOGLE_ADS' => [
                'developerToken' => $this->getConfig()->getDeveloperToken(),
                'loginCustomerId' => $this->getConfig()->getCustomerId(),
            ],
        ]);
    }
}
