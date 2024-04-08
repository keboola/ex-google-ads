<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Configuration;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->ignoreExtraKeys(false)
            ->children()
                ->arrayNode('customerId')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->beforeNormalization()
                        ->ifString()->then(fn($v) => [$v])
                    ->end()
                    ->scalarPrototype()->end()
                ->end()
                ->booleanNode('onlyEnabledCustomers')->defaultTrue()->end()
                ->scalarNode('since')->end()
                ->scalarNode('until')->end()
                ->scalarNode('#developerToken')->end()
                ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('query')->isRequired()->cannotBeEmpty()->end()
                ->arrayNode('primary')->scalarPrototype()->end()->end()
            ->end()
        ;

        // @formatter:on
        return $parametersNode;
    }
}
