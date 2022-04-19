<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\Configuration;

use Keboola\Component\Config\BaseConfigDefinition;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

class ConfigActionDefinition extends BaseConfigDefinition
{
    protected function getParametersDefinition(): ArrayNodeDefinition
    {
        $parametersNode = parent::getParametersDefinition();
        // @formatter:off
        /** @noinspection NullPointerExceptionInspection */
        $parametersNode
            ->children()
                ->booleanNode('getAccountChildren')->defaultFalse()
            ->end()
        ;
        // @formatter:on
        return $parametersNode;
    }
}
