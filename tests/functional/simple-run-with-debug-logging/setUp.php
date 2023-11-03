<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\FunctionalTests\DatadirTest;

use Keboola\GoogleAds\FunctionalTests\DatadirTest;

return function (DatadirTest $test): void {
    putenv('KBC_COMPONENT_RUN_MODE=debug');
};
