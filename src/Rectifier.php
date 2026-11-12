<?php

declare(strict_types=1);

namespace Depersp;

use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * Loads an uploaded photo, finds the framed sheet and writes a
 * perspective-corrected ("depersp") copy. The original file is never modified.
 */
final class Rectifier
{
    /** MIME type => ImageMagick coder. The coder is forced on read, so a file
     *  can never be interpreted as SVG/MVG/PS/... whatever its content claims. */
    private const ALLOWED = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
    ];

    private const OUTPUT_FORMATS = ['png' => 'PNG', 'jpg' => 'JPEG', 'jpeg' => 'JPEG', 'webp' => 'WEBP'];

    private readonly QuadDetector $detector;

    public function __construct(
        ?QuadDetector $detector = null,
        /** Force the output size (both null = derive it from the photo). */
        private readonly ?int $outputWidth = null,
        private readonly ?int $outputHeight = null,
        /** Longest side of the output when the size is derived automatically. */
        private readonly int $maxOutputSide = 2048,
        /** Fraction of the output trimmed on each side, to hide frame residue. */
        private readonly float $margin = 0.01,
        /** Lossy output quality (JPEG/WebP). */
        private readonly int $quality = 90,
        /** Reject inputs larger than this (bytes / pixels). */
        private readonly int $maxFileBytes = 25 * 1024 * 1024,
        private readonly int $maxPixels = 50_000_000,
        /** Resample in linear light: marginally better on photos, ~2x slower. */
        private readonly bool $linearLight = false,
    ) {
        $this->detector = $detector ?? new QuadDetector();
        if (($outputWidth === null) !== ($outputHeight === null)) {
            throw new \InvalidArgumentException('Set both outputWidth and outputHeight, or neither.');
        }
    }

    /**
     * Rectify $inputPath and write the result to $outputPath (format taken from
     * its extension: png, jpg/jpeg or webp). The file is written atomically.
     *
     * @throws InvalidImageException when the input cannot be used
     * @throws DetectionException    when no frame is found
     */
    public function rectifyFile(string $inputPath, string $outputPath): Result
    {
        $start = microtime(true);
        $format = self::OUTPUT_FORMATS[strtolower(pathinfo($outputPath, PATHINFO_EXTENSION))] ?? null;
        if ($format === null) {
            throw new \InvalidArgumentException('Output must end in .png, .jpg, .jpeg or .webp');
        }

        $image = $this->load($inputPath);
        try {
            [$out, $corners] = $this->rectify($image);
        } finally {
            $image->clear();
        }

        try {
            $out->setImageFormat($format);
            if ($format !== 'PNG') {
                $out->setImageCompressionQuality($this->quality);
            }
            $tmp = $outputPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (!$out->writeImage($format . ':' . $tmp) || !rename($tmp, $outputPath)) {
                @unlink($tmp);
                throw new DeperspException('Cannot write ' . $outputPath);
            }
            $result = new Result($outputPath, $corners, $out->getImageWidth(), $out->getImageHeight(), microtime(true) - $start);
        } catch (ImagickException $e) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            throw new DeperspException('Cannot write ' . $outputPath . ': ' . $e->getMessage(), 0, $e);
        } finally {
            $out->clear();
        }

        return $result;
    }

    /**
     * Rectify an already-loaded image (not modified).
     *
     * @return array{0:Imagick,1:list<array{0:float,1:float}>} output image and detected corners
     */
    public function rectify(Imagick $image): array
    {
        $corners = $this->detector->detect($image);
        [$w, $h] = $this->outputSize($corners, $image->getImageWidth(), $image->getImageHeight());

        $mx = $w * $this->margin;
        $my = $h * $this->margin;
        $dst = [[-$mx, -$my], [$w + $mx, -$my], [$w + $mx, $h + $my], [-$mx, $h + $my]];
        $args = [];
        foreach ($corners as $k => $c) {
            array_push($args, $c[0], $c[1], $dst[$k][0], $dst[$k][1]);
        }

        $out = clone $image;
        if ($this->linearLight) {
            $out->transformImageColorspace(Imagick::COLORSPACE_RGB);
        }
        $out->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_EDGE);
        $out->setImageArtifact('distort:viewport', sprintf('%dx%d+0+0', $w, $h));
        $out->distortImage(Imagick::DISTORTION_PERSPECTIVE, $args, false);
        if ($this->linearLight) {
            $out->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        $out->setImagePage($w, $h, 0, 0);
        $out->stripImage(); // drop EXIF (GPS etc.) from published files

        return [$out, $corners];
    }

    /** @return array{0:int,1:int} */
    private function outputSize(array $c, int $imgW, int $imgH): array
    {
        if ($this->outputWidth !== null) {
            return [$this->outputWidth, $this->outputHeight];
        }
        $ratio = Geometry::estimateAspectRatio($c, $imgW, $imgH);
        // Keep roughly the resolution the sheet has in the photo.
        $widthPx = max(Geometry::distance($c[0], $c[1]), Geometry::distance($c[3], $c[2]));
        $heightPx = max(Geometry::distance($c[0], $c[3]), Geometry::distance($c[1], $c[2]));
        $side = min($this->maxOutputSide, max($widthPx, $heightPx));
        if ($ratio >= 1) {
            $w = $side;
            $h = $side / $ratio;
        } else {
            $h = $side;
            $w = $side * $ratio;
        }

        return [max(1, (int) round($w)), max(1, (int) round($h))];
    }

    /** Validate and decode an input file, normalising orientation and alpha. */
    public function load(string $path): Imagick
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidImageException('File not found or not readable.');
        }
        $size = filesize($path);
        if ($size === false || $size === 0 || $size > $this->maxFileBytes) {
            throw new InvalidImageException('File is empty or too large.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $coder = self::ALLOWED[$mime] ?? null;
        if ($coder === null) {
            throw new InvalidImageException('Unsupported file type: ' . $mime);
        }

        try {
            $probe = new Imagick();
            $probe->pingImage($coder . ':' . $path);
            $pixels = $probe->getImageWidth() * $probe->getImageHeight();
            $probe->clear();
            if ($pixels <= 0 || $pixels > $this->maxPixels) {
                throw new InvalidImageException('Image dimensions are out of range.');
            }

            $img = new Imagick();
            $img->readImage($coder . ':' . $path);
            if ($img->getNumberImages() > 1) {
                $img->setIteratorIndex(0);
                $first = $img->getImage();
                $img->clear();
                $img = $first;
            }
            $img->autoOrient();
            if ($img->getImageAlphaChannel()) {
                $img->setImageBackgroundColor(new ImagickPixel('white'));
                $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            }
            $img->setImagePage(0, 0, 0, 0);

            return $img;
        } catch (ImagickException $e) {
            throw new InvalidImageException('Cannot decode image: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Apply conservative ImageMagick resource limits for a web worker.
     * Call once at bootstrap; values are in bytes / pixels / seconds.
     */
    public static function applyResourceLimits(int $memory = 256 * 1024 * 1024, int $time = 30): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, $memory);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, $memory * 2);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, $time);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_THREAD, 2);
    }
}
