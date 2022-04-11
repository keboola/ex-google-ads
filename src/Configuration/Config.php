<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Configuration;

use Keboola\Component\Config\BaseConfig;
use Keboola\Component\UserException;

class Config extends BaseConfig
{
    public function getName(): string
    {
        return $this->getValue(['parameters', 'name']);
    }

    public function getQuery(): string
    {
        return $this->getValue(['parameters', 'query']);
    }

    /**
     * @return string[]
     */
    public function getCustomersId(): array
    {
        $customers = $this->getValue(['parameters', 'customerId']);
        return array_map(fn($v) => str_replace('-', '', $v), $customers);
    }

    public function getSince(): ?string
    {
        $since = $this->getValue(['parameters', 'since'], '-1 day');
        if ($since) {
            return $this->getDate($since, 'since');
        }
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getPrimaryKeys(): array
    {
        return $this->getValue(['parameters', 'primary'], []);
    }

    public function getUntil(): ?string
    {
        $until = $this->getValue(['parameters', 'until'], '-1 day');
        if ($until) {
            return $this->getDate($until, 'until');
        }
        return null;
    }

    public function getDeveloperToken(): string
    {
        $localDeveloperToken = $this->getValue(['parameters', '#developerToken'], false);
        if ($localDeveloperToken) {
            return $localDeveloperToken;
        }
        $imageParameters = $this->getImageParameters();
        if (!isset($imageParameters['#developer_token'])) {
            throw new UserException('Developer token doesn\'t set.');
        }
        return $imageParameters['#developer_token'];
    }

    protected function getDate(string $date, string $name): string
    {
        $time = strtotime($date);
        if ($time === false) {
            throw new UserException("Date $name in configuration is invalid.");
        }
        return date('Y-m-d', $time);
    }
}
