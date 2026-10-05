<?php

namespace Wink\Images\Drivers;

use Wink\Images\InvalidImageException;

class GdDriver implements ImageDriver
{
    public function load(string $bytes)
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            throw new InvalidImageException('The file could not be read as an image.');
        }

        // Flatten onto white, which also converts palette images to truecolor.
        $image = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopy($image, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagedestroy($source);

        return $this->orient($image, $bytes);
    }

    public function width($image): int
    {
        return imagesx($image);
    }

    public function height($image): int
    {
        return imagesy($image);
    }

    public function crop($image, int $x, int $y, int $width, int $height)
    {
        $cropped = imagecreatetruecolor($width, $height);
        imagecopy($cropped, $image, 0, 0, $x, $y, $width, $height);

        return $cropped;
    }

    public function resize($image, int $width, int $height)
    {
        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    public function pixels($image): array
    {
        $pixels = [];

        for ($y = 0, $height = imagesy($image), $width = imagesx($image); $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);

                $pixels[] = ($color >> 16) & 0xFF;
                $pixels[] = ($color >> 8) & 0xFF;
                $pixels[] = $color & 0xFF;
            }
        }

        return $pixels;
    }

    public function withPixels($image, array $pixels)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $result = imagecreatetruecolor($width, $height);

        $i = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++, $i += 3) {
                imagesetpixel($result, $x, $y, ($pixels[$i] << 16) | ($pixels[$i + 1] << 8) | $pixels[$i + 2]);
            }
        }

        return $result;
    }

    public function webp($image, int $quality): string
    {
        // GD never writes EXIF or other metadata.
        ob_start();
        imagewebp($image, null, $quality);

        return ob_get_clean();
    }

    /**
     * Rotate the pixels to match the EXIF orientation, as browsers display it.
     */
    private function orient($image, string $bytes)
    {
        if (! function_exists('exif_read_data') || strncmp($bytes, "\xFF\xD8", 2) !== 0) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));

        switch ($exif['Orientation'] ?? 1) {
            case 2: imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 3: $image = imagerotate($image, 180, 0); break;
            case 4: imageflip($image, IMG_FLIP_VERTICAL); break;
            case 5: $image = imagerotate($image, -90, 0); imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 6: $image = imagerotate($image, -90, 0); break;
            case 7: $image = imagerotate($image, 90, 0); imageflip($image, IMG_FLIP_HORIZONTAL); break;
            case 8: $image = imagerotate($image, 90, 0); break;
        }

        return $image;
    }
}
