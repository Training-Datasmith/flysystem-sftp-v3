<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Double;

use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\Flysystem\Visibility;

class FixedVisibilityConverter implements VisibilityConverter
{
    public function forFile(string $visibility): int
    {
        return $visibility === Visibility::PUBLIC ? 0641 : 0601;
    }

    public function forDirectory(string $visibility): int
    {
        return $visibility === Visibility::PUBLIC ? 0751 : 0701;
    }

    public function inverseForFile(int $visibility): string
    {
        if ($visibility === 0641) {
            return Visibility::PUBLIC;
        }
        if ($visibility === 0601) {
            return Visibility::PRIVATE;
        }

        return Visibility::PUBLIC;
    }

    public function inverseForDirectory(int $visibility): string
    {
        if ($visibility === 0751) {
            return Visibility::PUBLIC;
        }
        if ($visibility === 0701) {
            return Visibility::PRIVATE;
        }

        return Visibility::PUBLIC;
    }

    public function defaultForDirectories(): int
    {
        return 0702;
    }
}
