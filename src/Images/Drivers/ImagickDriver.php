<?php

namespace Wink\Images\Drivers;

use Imagick;
use ImagickException;
use ImagickPixel;
use Wink\Images\InvalidImageException;

class ImagickDriver implements ImageDriver
{
    public function load(string $bytes)
    {
        try {
            $source = new Imagick();
            $source->readImageBlob($bytes);
            $source->setFirstIterator();

            $image = $source->getImage();
        } catch (ImagickException $e) {
            throw new InvalidImageException('The file could not be read as an image.', 0, $e);
        }

        $this->orient($image);

        if ($image->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }

        if ($image->getImageAlphaChannel()) {
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $image->setImageDepth(8);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    public function width($image): int
    {
        return $image->getImageWidth();
    }

    public function height($image): int
    {
        return $image->getImageHeight();
    }

    public function crop($image, int $x, int $y, int $width, int $height)
    {
        $image = clone $image;
        $image->cropImage($width, $height, $x, $y);
        $image->setImagePage(0, 0, 0, 0);

        return $image;
    }

    public function resize($image, int $width, int $height)
    {
        $image = clone $image;
        $image->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);

        return $image;
    }

    public function pixels($image): array
    {
        return $image->exportImagePixels(
            0, 0, $image->getImageWidth(), $image->getImageHeight(), 'RGB', Imagick::PIXEL_CHAR
        );
    }

    public function withPixels($image, array $pixels)
    {
        $image = clone $image;
        $image->importImagePixels(
            0, 0, $image->getImageWidth(), $image->getImageHeight(), 'RGB', Imagick::PIXEL_CHAR, $pixels
        );

        return $image;
    }

    public function webp($image, int $quality): string
    {
        $image = clone $image;
        $image->stripImage();
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality($quality);

        return $image->getImageBlob();
    }

    /**
     * Rotate the pixels to match the EXIF orientation, as browsers display it.
     */
    private function orient(Imagick $image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();

            return;
        }

        switch ($image->getImageOrientation()) {
            case Imagick::ORIENTATION_TOPRIGHT: $image->flopImage(); break;
            case Imagick::ORIENTATION_BOTTOMRIGHT: $image->rotateImage('white', 180); break;
            case Imagick::ORIENTATION_BOTTOMLEFT: $image->flipImage(); break;
            case Imagick::ORIENTATION_LEFTTOP: $image->rotateImage('white', 90); $image->flopImage(); break;
            case Imagick::ORIENTATION_RIGHTTOP: $image->rotateImage('white', 90); break;
            case Imagick::ORIENTATION_RIGHTBOTTOM: $image->rotateImage('white', -90); $image->flopImage(); break;
            case Imagick::ORIENTATION_LEFTBOTTOM: $image->rotateImage('white', -90); break;
        }

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
