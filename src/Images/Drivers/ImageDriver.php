<?php

namespace Wink\Images\Drivers;

/**
 * The few image operations the featured image pipeline needs.
 *
 * Handles are driver specific (an Imagick instance or a GD image) and are
 * only ever passed back to the driver that created them.
 */
interface ImageDriver
{
    /**
     * Decode image bytes, apply EXIF orientation and flatten to RGB.
     *
     * @throws \Wink\Images\InvalidImageException
     */
    public function load(string $bytes);

    public function width($image): int;

    public function height($image): int;

    public function crop($image, int $x, int $y, int $width, int $height);

    public function resize($image, int $width, int $height);

    /**
     * Flat list of RGB bytes (r, g, b, r, g, b, ...), row by row.
     *
     * @return int[]
     */
    public function pixels($image): array;

    /**
     * @param  int[]  $pixels
     */
    public function withPixels($image, array $pixels);

    /**
     * Encode as WebP with all metadata stripped.
     */
    public function webp($image, int $quality): string;
}
