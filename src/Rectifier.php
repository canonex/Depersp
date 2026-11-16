<?php

declare(strict_types=1);

namespace Depersp;

use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * Finds the framed sheet in a photo and produces a perspective-corrected copy.
 *
 * Two entry points:
 *  - rectifyBlob(): bytes in, bytes out, nothing written to disk (recommended
 *    for uploads: the photo never needs to be stored);
 *  - rectifyFile(): file in, file out. The input file is never modified.
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

    private const MIME_TYPES = ['PNG' => 'image/png', 'JPEG' => 'image/jpeg', 'WEBP' => 'image/webp'];

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
     * Rectify an image held in memory and return the encoded result in
     * Result::$data. No file is created: suitable when uploads must not be stored.
     *
     * @param string $format output format: jpg/jpeg, png or webp
     *
     * @throws InvalidImageException when the input cannot be used
     * @throws DetectionException    when no frame is found
     */
    public function rectifyBlob(string $bytes, string $format = 'jpg'): Result
    {
        $start = microtime(true);
        $coder = self::outputCoder($format);
        $image = $this->loadBlob($bytes);

        return $this->encode($image, $coder, null, $start);
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
        $coder = self::outputCoder(pathinfo($outputPath, PATHINFO_EXTENSION));
        $image = $this->load($inputPath);

        return $this->encode($image, $coder, $outputPath, $start);
    }

    private static function outputCoder(string $format): string
    {
        return self::OUTPUT_FORMATS[strtolower($format)]
            ?? throw new \InvalidArgumentException('Output format must be png, jpg, jpeg or webp');
    }

    /** Rectify $image (consumed) and encode it to $outputPath, or to memory when null. */
    private function encode(Imagick $image, string $coder, ?string $outputPath, float $start): Result
    {
        try {
            [$out, $corners] = $this->rectify($image);
        } finally {
            $image->clear();
        }

        $tmp = null;
        try {
            // Both are needed: the wand format forced by loadBlob() would
            // otherwise win over the image format in getImageBlob().
            $out->setFormat($coder);
            $out->setImageFormat($coder);
            if ($coder !== 'PNG') {
                $out->setImageCompressionQuality($this->quality);
            }
            $data = null;
            if ($outputPath === null) {
                $data = $out->getImageBlob();
            } else {
                $tmp = $outputPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (!$out->writeImage($coder . ':' . $tmp) || !rename($tmp, $outputPath)) {
                    throw new DeperspException('Cannot write ' . $outputPath);
                }
                $tmp = null;
            }

            return new Result(
                $outputPath,
                $corners,
                $out->getImageWidth(),
                $out->getImageHeight(),
                microtime(true) - $start,
                self::MIME_TYPES[$coder],
                $data,
            );
        } catch (ImagickException $e) {
            throw new DeperspException('Cannot encode the result: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $out->clear();
        }
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
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidImageException('File not readable.');
        }

        return $this->loadBlob($bytes);
    }

    /** Validate and decode an image held in memory. */
    public function loadBlob(string $bytes): Imagick
    {
        $size = strlen($bytes);
        if ($size === 0 || $size > $this->maxFileBytes) {
            throw new InvalidImageException('Image is empty or too large.');
        }
        // MIME sniffed from the content, never from a file name or client header.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $coder = self::ALLOWED[$mime] ?? null;
        if ($coder === null) {
            throw new InvalidImageException('Unsupported file type: ' . $mime);
        }

        try {
            // setFormat() forces the decoder: the bytes can never be
            // interpreted as SVG/MVG/PS/... whatever they contain.
            $probe = new Imagick();
            $probe->setFormat($coder);
            $probe->pingImageBlob($bytes);
            $pixels = $probe->getImageWidth() * $probe->getImageHeight();
            $probe->clear();
            if ($pixels <= 0 || $pixels > $this->maxPixels) {
                throw new InvalidImageException('Image dimensions are out of range.');
            }

            $img = new Imagick();
            $img->setFormat($coder);
            $img->readImageBlob($bytes);
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
