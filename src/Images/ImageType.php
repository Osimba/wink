<?php

namespace Wink\Images;

class ImageType
{
    /**
     * The image types Wink accepts, by file extension.
     */
    public const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Identify the image from its bytes, never from a name or header.
     *
     * @return string The file extension to store it under.
     *
     * @throws InvalidImageException
     */
    public static function extension(string $bytes): string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::ALLOWED[$info[2]])) {
            throw new InvalidImageException('Only JPEG, PNG and WebP images are supported.');
        }

        return self::ALLOWED[$info[2]];
    }
}
