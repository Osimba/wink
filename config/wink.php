<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Wink Database Connection
    |--------------------------------------------------------------------------
    |
    | This is the database connection you want Wink to use while storing &
    | reading your content. By default Wink assumes you've prepared a
    | new connection called "wink". However, you can change that
    | to anything you want.
    |
    */

    'database_connection' => env('WINK_DB_CONNECTION', 'wink'),

    /*
    |--------------------------------------------------------------------------
    | Wink Uploads Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Wink will use to put file uploads, you can use
    | any of the disks defined in your config/filesystems.php file. You may
    | also configure the path where the files should be stored.
    |
    */

    'storage_disk' => env('WINK_STORAGE_DISK', 'local'),

    'storage_path' => env('WINK_STORAGE_PATH', 'public/wink/images'),

    /*
    |--------------------------------------------------------------------------
    | Remote Images
    |--------------------------------------------------------------------------
    |
    | Authors may import an image by pasting its URL instead of uploading a
    | file. The server downloads it, so private and reserved addresses are
    | always refused. Only JPEG, PNG and WebP images are accepted.
    |
    */

    'remote_images' => [
        'enabled' => env('WINK_REMOTE_IMAGES', true),
        'timeout' => 10,
        'max_bytes' => 15 * 1024 * 1024,
        'max_redirects' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Featured Images
    |--------------------------------------------------------------------------
    |
    | Featured images are cropped to one ratio, resized, optionally given the
    | house grade, and encoded as WebP with metadata stripped. Originals are
    | kept privately so a post's image can be re-cropped later. The grade
    | constants mirror the reference Pillow script; tune them here.
    |
    */

    'featured_image' => [
        'width' => 1200,
        'height' => 520,
        'variants' => [800],
        'quality' => 82,
        'preview_width' => 600,
        'originals_path' => env('WINK_ORIGINALS_PATH', 'wink/originals'),
        'driver' => env('WINK_IMAGE_DRIVER', 'auto'), // auto, imagick or gd

        'grade' => [
            'default' => true,
            'target_luma' => 132,
            'clamp' => [0.75, 1.35],
            'saturation' => 0.82,
            'navy' => [14, 34, 52],
            'navy_strength' => 0.07,
            'contrast' => 1.06,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wink Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Wink will be accessible from. By default it
    | will be accessible on the same domain as your app.
    |
    */

    'domain' => env('WINK_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Wink Path
    |--------------------------------------------------------------------------
    |
    | This is the URI prefix where Wink will be accessible from. Feel free to
    | change this path to anything you like.
    |
    */

    'path' => env('WINK_PATH', 'wink'),

    /*
    |--------------------------------------------------------------------------
    | Wink Middleware Group
    |--------------------------------------------------------------------------
    |
    | This is the middleware group that Wink uses.
    |
    */

    'middleware_group' => env('WINK_MIDDLEWARE_GROUP', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Wink Post Preview Path
    |--------------------------------------------------------------------------
    |
    | Wink uses this path to display a preview link in the editor. While
    | building the link tag, the {postSlug} placeholder will be replaced
    | by the actual post slug.
    |
    */

    'preview_path' => '/{postSlug}',

    'editor' => [

        /*
        |--------------------------------------------------------------------------
        | Default editor (for when you don't want options)
        |--------------------------------------------------------------------------
        |
        | Wink usually allows either markdown or rich text editing. If you're
        | setting up an environment where you only want one or the other
        | you can specify that here. (options: null, 'markdown', 'rich')
        |
        */

        'default' => null,

    ],

    /*
    |--------------------------------------------------------------------------
    | The pagination of wink collections
    |--------------------------------------------------------------------------
    |
    | You can configure here the number of items, per page.
    |
    */
    'pagination' => [
        'posts' => 30,
        'tags' => 30,
        'teams' => 30,
        'pages' => 30,
    ],
];
