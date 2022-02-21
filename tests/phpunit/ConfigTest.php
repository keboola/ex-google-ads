<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Tests;

use Generator;
use Keboola\GoogleAds\Configuration\Config;
use Keboola\GoogleAds\Configuration\ConfigDefinition;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class ConfigTest extends TestCase
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint
     * @dataProvider validConfigDataProvider
     */
    public function testValidConfig(array $configData, string $expectedToken): void
    {
        $config = new Config($configData, new ConfigDefinition());

        Assert::assertEquals($expectedToken, $config->getDeveloperToken());
        Assert::assertEquals($configData, $config->getData());
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint
     * @dataProvider invalidConfigDataProvider
     */
    public function testInvalidConfig(array $configData, string $expectedErrorMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($expectedErrorMessage);
        new Config($configData, new ConfigDefinition());
    }

    public function validConfigDataProvider(): Generator
    {
        yield 'min-config' => [
            [
                'parameters' => [
                    'customerId' => 1234567890,
                    'name' => 'testName',
                    'query' => 'testQuery',
                    'primary' => [],
                ],
                'image_parameters' => [
                    '#developer_token' => 'imageToken',
                ],
            ],
            'imageToken',
        ];

        yield 'config-with-date' => [
            [
                'parameters' => [
                    'customerId' => 1234567890,
                    'name' => 'testName',
                    'since' => '20200101',
                    'until' => '20220203',
                    'query' => 'testQuery',
                    'primary' => [],
                ],
                'image_parameters' => [
                    '#developer_token' => 'imageToken',
                ],
            ],
            'imageToken',
        ];

        yield 'config-with-primary' => [
            [
                'parameters' => [
                    'customerId' => 1234567890,
                    'name' => 'testName',
                    'query' => 'testQuery',
                    'primary' => [
                        'primary1',
                        'primary2',
                        'primary3',
                        'primary4',
                    ],
                ],
                'image_parameters' => [
                    '#developer_token' => 'imageToken',
                ],
            ],
            'imageToken',
        ];

        yield 'config-with-dev-token' => [
            [
                'parameters' => [
                    'customerId' => 1234567890,
                    'name' => 'testName',
                    'query' => 'testQuery',
                    'primary' => [],
                    '#developerToken' => 'paramsToken',
                ],
                'image_parameters' => [
                    '#developer_token' => 'imageToken',
                ],
            ],
            'paramsToken',
        ];
    }

    public function invalidConfigDataProvider(): Generator
    {
        yield 'empty-config' => [
            [
                'parameters' => [],
            ],
            'The child config "customerId" under "root.parameters" must be configured.',
        ];

        yield 'missing-name' => [
            [
                'parameters' => [
                    'customerId' => 'testCustomer',
                ],
            ],
            'The child config "name" under "root.parameters" must be configured.',
        ];

        yield 'missing-query' => [
            [
                'parameters' => [
                    'customerId' => 'testCustomer',
                    'name' => 'testName',
                ],
            ],
            'The child config "query" under "root.parameters" must be configured.',
        ];
    }
}
