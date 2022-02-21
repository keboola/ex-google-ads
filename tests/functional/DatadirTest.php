<?php

declare(strict_types=1);

namespace Keboola\GoogleAds\FunctionalTests;

use Keboola\DatadirTests\DatadirTestCase;
use Symfony\Component\Finder\Finder;
use Throwable;

class DatadirTest extends DatadirTestCase
{
    public function assertDirectoryContentsSame(string $expected, string $actual): void
    {
        $this->prettifyAllManifests($actual);
        parent::assertDirectoryContentsSame($expected, $actual);
    }

    protected function prettifyAllManifests(string $actual): void
    {
        foreach ($this->findManifests($actual . '/tables') as $file) {
            $this->prettifyJsonFile((string) $file->getRealPath());
        }
    }

    protected function prettifyJsonFile(string $path): void
    {
        $json = (string) file_get_contents($path);
        try {
            file_put_contents($path, (string) json_encode(json_decode($json), JSON_PRETTY_PRINT));
        } catch (Throwable $e) {
            // If a problem occurs, preserve the original contents
            file_put_contents($path, $json);
        }
    }

    protected function findManifests(string $dir): Finder
    {
        $finder = new Finder();
        return $finder->files()->in($dir)->name(['~.*\.manifest~']);
    }
}
