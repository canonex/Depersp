<?php

declare(strict_types=1);

namespace Depersp;

/** Outcome of a successful rectification. */
final class Result
{
    /**
     * @param string|null $outputPath file written by rectifyFile(), null for rectifyBlob()
     * @param list<array{0:float,1:float}> $corners TL, TR, BR, BL in the (auto-oriented) source image
     * @param string|null $data encoded image from rectifyBlob(), null for rectifyFile()
     */
    public function __construct(
        public readonly ?string $outputPath,
        public readonly array $corners,
        public readonly int $width,
        public readonly int $height,
        public readonly float $seconds,
        public readonly string $mimeType,
        public readonly ?string $data = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'output' => $this->outputPath,
            'mime' => $this->mimeType,
            'corners' => array_map(static fn ($p) => [round($p[0], 2), round($p[1], 2)], $this->corners),
            'width' => $this->width,
            'height' => $this->height,
            'seconds' => round($this->seconds, 3),
        ];
    }
}
