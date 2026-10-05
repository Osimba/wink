<?php

namespace Wink;

class Wink
{
    /**
     * Get the default JavaScript variables for Wink.
     *
     * @return array
     */
    public static function scriptVariables()
    {
        return [
            'unsplash_key' => config('services.unsplash.key'),
            'path' => config('wink.path'),
            'preview_path' => config('wink.preview_path'),
            'author' => auth('wink')->check() ? auth('wink')->user()->only('name', 'avatar', 'id') : null,
            'default_editor' => config('wink.editor.default'),
            'remote_images' => (bool) config('wink.remote_images.enabled', true),
            'featured_image' => [
                'width' => (int) config('wink.featured_image.width', 1200),
                'height' => (int) config('wink.featured_image.height', 520),
                'grade' => (bool) config('wink.featured_image.grade.default', true),
            ],
        ];
    }
}
