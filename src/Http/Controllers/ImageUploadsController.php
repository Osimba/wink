<?php

namespace Wink\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wink\Images\FeaturedImageProcessor;
use Wink\Images\ImageType;
use Wink\Images\InvalidImageException;
use Wink\Support\RemoteFetchException;
use Wink\Support\RemoteImageFetcher;

class ImageUploadsController
{
    /**
     * Upload a new image.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload()
    {
        request()->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp|max:'.self::maxKilobytes(),
        ]);

        $path = request()->image->store(config('wink.storage_path'), [
            'disk' => config('wink.storage_disk'),
            'visibility' => 'public',
        ]
        );

        return response()->json([
            'url' => Storage::disk(config('wink.storage_disk'))->url($path),
        ]);
    }

    /**
     * Import an image from a URL, storing it exactly as downloaded.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fromUrl()
    {
        abort_unless(config('wink.remote_images.enabled', true), 404);

        request()->validate(['url' => 'required|string|max:2048']);

        try {
            $bytes = app(RemoteImageFetcher::class)->fetch(request('url'));
            $extension = ImageType::extension($bytes);

            FeaturedImageProcessor::makeDriver(config('wink.featured_image.driver', 'auto'))->load($bytes);
        } catch (RemoteFetchException|InvalidImageException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $path = config('wink.storage_path').'/'.Str::random(40).'.'.$extension;

        Storage::disk(config('wink.storage_disk'))->put($path, $bytes, 'public');

        return response()->json([
            'url' => Storage::disk(config('wink.storage_disk'))->url($path),
        ]);
    }

    /**
     * The largest image Wink accepts, in kilobytes.
     */
    public static function maxKilobytes(): int
    {
        return intdiv((int) config('wink.remote_images.max_bytes', 15 * 1024 * 1024), 1024);
    }
}
