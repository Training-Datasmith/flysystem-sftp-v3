<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Double;

use League\MimeTypeDetection\MimeTypeDetector;

class FixedMimeTypeDetector implements MimeTypeDetector
{
    public function __construct(
        private ?string $fromContents = 'application/x-plan-test',
        private ?string $fromPath = 'text/x-from-path',
        private bool $throwOnDetect = false,
    ) {
    }

    /**
     * @param string|resource $contents
     */
    public function detectMimeType(string $path, $contents): ?string
    {
        if ($this->throwOnDetect) {
            throw new \RuntimeException('detector failed');
        }

        return $this->fromContents;
    }

    public function detectMimeTypeFromBuffer(string $contents): ?string
    {
        return $this->fromContents;
    }

    public function detectMimeTypeFromPath(string $path): ?string
    {
        return $this->fromPath;
    }

    public function detectMimeTypeFromFile(string $path): ?string
    {
        return $this->fromPath;
    }
}
