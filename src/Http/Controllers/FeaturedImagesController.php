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

class FeaturedImagesController
{
    /**
     * Stored originals are named "{uuid}.{extension}".
     */
    private const SOURCE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp)$/';

    /**
     * Store an uploaded or downloaded original, untouched and private.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeSource()
    {
        request()->validate([
            'image' => 'required_without:url|file|mimes:jpeg,jpg,png,webp|max:'.ImageUploadsController::maxKilobytes(),
            'url' => 'required_without:image|nullable|string|max:2048',
        ]);

        $field = request()->hasFile('image') ? 'image' : 'url';

        try {
            if ($field === 'image') {
                $bytes = file_get_contents(request()->file('image')->getRealPath());
            } else {
                abort_unless(config('wink.remote_images.enabled', true), 404);

                $bytes = app(RemoteImageFetcher::class)->fetch(request('url'));
            }

            $extension = ImageType::extension($bytes);
            $processor = FeaturedImageProcessor::fromConfig();
            $dimensions = $processor->dimensions($bytes);
        } catch (RemoteFetchException|InvalidImageException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        $source = Str::uuid().'.'.$extension;

        $this->disk()->put($this->sourcePath($source), $bytes, 'private');

        return response()->json([
            'source' => $source,
            'source_url' => $field === 'url' ? request('url') : null,
            'preview_url' => route('wink.featured-images.sources.show', ['source' => $source]),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'default_crop' => $processor->defaultCrop($dimensions['width'], $dimensions['height']),
        ]);
    }

    /**
     * Stream a stored original to the editor.
     *
     * @param  string  $source
     * @return \Illuminate\Http\Response
     */
    public function showSource($source)
    {
        abort_unless(preg_match(self::SOURCE_PATTERN, $source, $matches), 404);
        abort_unless($this->disk()->exists($this->sourcePath($source)), 404);

        return response($this->disk()->get($this->sourcePath($source)), 200, [
            'Content-Type' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Small ungraded and graded renders of a crop, without storing anything.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview()
    {
        [$bytes, $crop] = $this->validatedSource();

        $processor = FeaturedImageProcessor::fromConfig();
        $width = (int) config('wink.featured_image.preview_width', 600);

        $render = function (bool $grade) use ($processor, $bytes, $crop, $width) {
            $image = $processor->render($bytes, $crop, $grade, $width)['images'][$width];

            return 'data:image/webp;base64,'.base64_encode($image);
        };

        return response()->json([
            'original' => $render(false),
            'graded' => $render(true),
        ]);
    }

    /**
     * Render, store and return the published featured image and variants.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store()
    {
        [$bytes, $crop, $source] = $this->validatedSource();

        $grade = request()->boolean('grade');
        $config = config('wink.featured_image');

        $result = FeaturedImageProcessor::fromConfig()->render($bytes, $crop, $grade, null, $config['variants'] ?? []);

        $name = Str::uuid();
        $urls = [];

        foreach ($result['images'] as $width => $image) {
            $path = config('wink.storage_path')."/featured/{$name}-{$width}.webp";

            Storage::disk(config('wink.storage_disk'))->put($path, $image, 'public');

            $urls[$width] = Storage::disk(config('wink.storage_disk'))->url($path);
        }

        ksort($urls);

        return response()->json([
            'url' => $urls[$config['width']],
            'original' => $source,
            'meta' => [
                'crop' => $result['crop'],
                'graded' => $grade,
                'width' => (int) $config['width'],
                'height' => (int) $config['height'],
                'variants' => $urls,
            ],
        ]);
    }

    /**
     * Validate a source and crop box from the request and load the original.
     *
     * @return array{0: string, 1: array, 2: string}
     */
    private function validatedSource(): array
    {
        $data = request()->validate([
            'source' => ['required', 'string', 'regex:'.self::SOURCE_PATTERN],
            'crop.x' => 'required|numeric|min:0',
            'crop.y' => 'required|numeric|min:0',
            'crop.width' => 'required|numeric|min:1',
            'crop.height' => 'required|numeric|min:1',
            'grade' => 'sometimes|boolean',
        ]);

        $path = $this->sourcePath($data['source']);

        if (! $this->disk()->exists($path)) {
            throw ValidationException::withMessages(['source' => 'The original image could not be found.']);
        }

        return [$this->disk()->get($path), $data['crop'], $data['source']];
    }

    private function sourcePath(string $source): string
    {
        return trim(config('wink.featured_image.originals_path', 'wink/originals'), '/').'/'.$source;
    }

    private function disk()
    {
        return Storage::disk(config('wink.storage_disk'));
    }
}
