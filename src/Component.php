<?php

declare(strict_types=1);

namespace Keboola\GoogleAds;

use Google\Ads\GoogleAds\Lib\Configuration;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClient;
use Google\Ads\GoogleAds\Lib\V17\GoogleAdsClientBuilder;
use Google\ApiCore\ApiException;
use GuzzleHttp\Exception\ClientException;
use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\GoogleAds\Configuration\Config;
use Keboola\GoogleAds\Configuration\ConfigActionDefinition;
use Keboola\GoogleAds\Configuration\ConfigDefinition;

class Component extends BaseComponent
{
    private const ACTION_LIST_ACCOUNTS = 'listAccounts';

    protected function run(): void
    {
        $customersIdDownloaded = [];
        foreach ($this->getConfig()->getCustomersId() as $customerId) {
            $googleAdsClient = $this->getGoogleAdsClient($customerId);
            $extractor = new Extractor(
                $googleAdsClient,
                $this->getConfig(),
                $this->getLogger(),
                $this->getManifestManager(),
                $this->getDataDir(),
                $customersIdDownloaded,
            );

            try {
                $customersIdDownloaded = $extractor->extract($customerId);
            } catch (ClientException $e) {
                if (in_array($e->getCode(), range(400, 499))) {
                    throw new UserException($e->getMessage(), $e->getCode(), $e);
                }
            } catch (ApiException $e) {
                $message = json_decode($e->getMessage(), true);
                throw new UserException(sprintf(
                    '%s: %s',
                    $e->getStatus(),
                    $message['message'] ?? $e->getMessage(),
                ));
            }
        }
    }

    /**
     * @return mixed[]
     */
    protected function runListAccounts(): array
    {
        $accountHierarchy = new GetAccountHierarchy(
            $this->getGoogleAdsClient(),
            $this->getConfig()->getAccountChildren(),
        );
        return $accountHierarchy->run();
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

    /**
     * @return array<string, string>
     */
    protected function getSyncActions(): array
    {
        return [
            self::ACTION_LIST_ACCOUNTS => 'runListAccounts',
        ];
    }

    protected function getConfigDefinitionClass(): string
    {
        $action = $this->getRawConfig()['action'] ?? 'run';
        switch ($action) {
            case self::ACTION_LIST_ACCOUNTS:
                return ConfigActionDefinition::class;
            default:
                return ConfigDefinition::class;
        }
    }

    private function getGoogleAdsClient(?string $customerId = null): GoogleAdsClient
    {
        $oauth = (new OAuth2TokenBuilder())->from($this->getOAuthConfiguration())->build();

        $googleAdsClient = (new GoogleAdsClientBuilder())
            ->withOAuth2Credential($oauth)
            ->from($this->getGoogleAdsConfiguration($customerId))
            ->build();

        return $googleAdsClient;
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

    private function getGoogleAdsConfiguration(?string $customerId = null): Configuration
    {
        $config = [
            'GOOGLE_ADS' => [
                'developerToken' => $this->getConfig()->getDeveloperToken(),
            ],
        ];

        if ($customerId) {
            $config['GOOGLE_ADS']['loginCustomerId'] = $customerId;
        }

        return new Configuration($config);
    }
}
