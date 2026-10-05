<?php

namespace Wink\Images;

use Wink\Images\Drivers\GdDriver;
use Wink\Images\Drivers\ImageDriver;
use Wink\Images\Drivers\ImagickDriver;

/**
 * Turns an original image and a crop box into the published featured image.
 *
 * Crop, resize to the configured size, grade (optional), then encode WebP.
 * Smaller variants are scaled down from the graded full-size image.
 */
class FeaturedImageProcessor
{
    /**
     * @var ImageDriver
     */
    private $driver;

    /**
     * @var array
     */
    private $config;

    public function __construct(ImageDriver $driver, array $config)
    {
        $this->driver = $driver;
        $this->config = $config;
    }

    /**
     * Build a processor from the wink.featured_image config.
     */
    public static function fromConfig(): self
    {
        $config = config('wink.featured_image');

        return new self(self::makeDriver($config['driver'] ?? 'auto'), $config);
    }

    /**
     * Imagick when it is installed (or asked for), GD otherwise.
     */
    public static function makeDriver(string $name): ImageDriver
    {
        if ($name === 'imagick' || ($name === 'auto' && extension_loaded('imagick'))) {
            return new ImagickDriver();
        }

        return new GdDriver();
    }

    /**
     * The upright dimensions of an image, as a browser displays it.
     *
     * @return array{width: int, height: int}
     *
     * @throws InvalidImageException
     */
    public function dimensions(string $bytes): array
    {
        $image = $this->driver->load($bytes);

        return ['width' => $this->driver->width($image), 'height' => $this->driver->height($image)];
    }

    /**
     * Render the featured image and its variants.
     *
     * @param  array  $crop  x, y, width and height in the upright original's pixels.
     * @param  int|null  $width  Output width; the configured width when null.
     * @return array{crop: array, images: array<int, string>} The crop actually
     *                                                          used, and WebP bytes keyed by width, largest first.
     *
     * @throws InvalidImageException
     */
    public function render(string $bytes, array $crop, bool $grade, ?int $width = null, array $variants = []): array
    {
        $image = $this->driver->load($bytes);
        $box = $this->fitCrop($crop, $this->driver->width($image), $this->driver->height($image));

        $width = $width ?: (int) $this->config['width'];
        $height = $this->heightFor($width);

        $image = $this->driver->crop($image, $box['x'], $box['y'], $box['width'], $box['height']);
        $image = $this->driver->resize($image, $width, $height);

        if ($grade) {
            $graded = (new HouseGrade($this->config['grade']))->apply($this->driver->pixels($image));
            $image = $this->driver->withPixels($image, $graded);
        }

        $quality = (int) $this->config['quality'];
        $output = [$width => $this->driver->webp($image, $quality)];

        foreach ($variants as $variant) {
            if ($variant < $width) {
                $output[$variant] = $this->driver->webp(
                    $this->driver->resize($image, $variant, $this->heightFor($variant)), $quality
                );
            }
        }

        return ['crop' => $box, 'images' => $output];
    }

    /**
     * Keep the crop box inside the image and at the configured ratio.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function fitCrop(array $crop, int $imageWidth, int $imageHeight): array
    {
        $ratio = $this->config['width'] / $this->config['height'];

        $width = max(1, min($imageWidth, (int) round($crop['width'] ?? $imageWidth)));
        $height = (int) round($width / $ratio);

        if ($height > $imageHeight) {
            $height = $imageHeight;
            $width = max(1, (int) round($height * $ratio));
        }

        $x = max(0, min($imageWidth - $width, (int) round($crop['x'] ?? 0)));
        $y = max(0, min($imageHeight - $height, (int) round($crop['y'] ?? 0)));

        return compact('x', 'y', 'width', 'height');
    }

    /**
     * The default crop: centred horizontally, 40% down the spare height.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function defaultCrop(int $imageWidth, int $imageHeight): array
    {
        $ratio = $this->config['width'] / $this->config['height'];

        if ($imageWidth / $imageHeight > $ratio) {
            $width = (int) ($imageHeight * $ratio);

            return ['x' => intdiv($imageWidth - $width, 2), 'y' => 0, 'width' => $width, 'height' => $imageHeight];
        }

        $height = (int) ($imageWidth / $ratio);

        return ['x' => 0, 'y' => (int) (($imageHeight - $height) * 0.4), 'width' => $imageWidth, 'height' => $height];
    }

    private function heightFor(int $width): int
    {
        return (int) round($width * $this->config['height'] / $this->config['width']);
    }
}
