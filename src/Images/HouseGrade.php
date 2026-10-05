<?php

namespace Wink\Images;

/**
 * The house grade for featured images, ported from the reference Pillow script.
 *
 * Pillow quantizes to 8 bits after every step, does its blend arithmetic in
 * 32-bit float and truncates the result, so each step here is a lookup table
 * built with the same float32 maths. Luma uses Pillow's fixed-point weights.
 */
class HouseGrade
{
    /**
     * @var array
     */
    private $config;

    /**
     * @param  array  $config  The wink.featured_image.grade config.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Grade a flat list of RGB bytes (r, g, b, r, g, b, ...).
     *
     * @param  int[]  $pixels
     * @return int[]
     */
    public function apply(array $pixels): array
    {
        $pixels = $this->exposure($pixels);
        $pixels = $this->saturation($pixels);
        $pixels = $this->navyWash($pixels);

        return $this->contrast($pixels);
    }

    /**
     * Step 1: scale every channel so the mean luma lands on the target.
     */
    public function exposure(array $pixels): array
    {
        $luma = self::meanLuma($pixels);

        if ($luma < 1) {
            return $pixels;
        }

        [$min, $max] = $this->config['clamp'];
        $factor = max($min, min($max, $this->config['target_luma'] / $luma));

        $lut = [];
        for ($value = 0; $value < 256; $value++) {
            $lut[$value] = self::blend(0, $value, $factor);
        }

        foreach ($pixels as $i => $value) {
            $pixels[$i] = $lut[$value];
        }

        return $pixels;
    }

    /**
     * Step 2: pull each channel toward the pixel's own luma.
     */
    public function saturation(array $pixels): array
    {
        $alpha = $this->config['saturation'];

        $lut = [];
        for ($gray = 0; $gray < 256; $gray++) {
            for ($value = 0; $value < 256; $value++) {
                $lut[($gray << 8) | $value] = self::blend($gray, $value, $alpha);
            }
        }

        for ($i = 0, $n = count($pixels); $i < $n; $i += 3) {
            $gray = self::luma($pixels[$i], $pixels[$i + 1], $pixels[$i + 2]) << 8;

            $pixels[$i] = $lut[$gray | $pixels[$i]];
            $pixels[$i + 1] = $lut[$gray | $pixels[$i + 1]];
            $pixels[$i + 2] = $lut[$gray | $pixels[$i + 2]];
        }

        return $pixels;
    }

    /**
     * Step 3: blend a thin layer of navy over the image.
     */
    public function navyWash(array $pixels): array
    {
        $strength = $this->config['navy_strength'];

        $luts = [];
        foreach ($this->config['navy'] as $channel => $navy) {
            for ($value = 0; $value < 256; $value++) {
                $luts[$channel][$value] = self::blend($value, $navy, $strength);
            }
        }

        [$red, $green, $blue] = $luts;

        for ($i = 0, $n = count($pixels); $i < $n; $i += 3) {
            $pixels[$i] = $red[$pixels[$i]];
            $pixels[$i + 1] = $green[$pixels[$i + 1]];
            $pixels[$i + 2] = $blue[$pixels[$i + 2]];
        }

        return $pixels;
    }

    /**
     * Step 4: push every channel away from the rounded mean luma.
     */
    public function contrast(array $pixels): array
    {
        $mean = (int) (self::meanLuma($pixels) + 0.5);

        $lut = [];
        for ($value = 0; $value < 256; $value++) {
            $lut[$value] = self::blend($mean, $value, $this->config['contrast']);
        }

        foreach ($pixels as $i => $value) {
            $pixels[$i] = $lut[$value];
        }

        return $pixels;
    }

    /**
     * Pillow's RGB to L conversion (ITU-R 601, fixed point, rounded).
     */
    public static function luma(int $r, int $g, int $b): int
    {
        return ($r * 19595 + $g * 38470 + $b * 7471 + 0x8000) >> 16;
    }

    /**
     * The mean of the image's luma channel.
     */
    public static function meanLuma(array $pixels): float
    {
        $count = intdiv(count($pixels), 3);

        if ($count === 0) {
            return 0.0;
        }

        $sum = 0;
        for ($i = 0, $n = $count * 3; $i < $n; $i += 3) {
            $sum += self::luma($pixels[$i], $pixels[$i + 1], $pixels[$i + 2]);
        }

        return $sum / $count;
    }

    /**
     * Pillow's ImagingBlend for one channel value: in1 + alpha * (in2 - in1),
     * computed in float32, clipped when extrapolating, then truncated.
     */
    public static function blend(int $in1, int $in2, float $alpha): int
    {
        $alpha32 = self::float32($alpha);
        $result = self::float32($in1 + self::float32($alpha32 * ($in2 - $in1)));

        if ($result <= 0.0) {
            return 0;
        }

        if ($result >= 255.0) {
            return 255;
        }

        return (int) $result;
    }

    /**
     * Round a double to the nearest single-precision float.
     */
    private static function float32(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }
}
