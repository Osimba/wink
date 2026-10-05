<?php

// Router for `php -S`, used by RemoteImageFetcherTest.

$png = function () {
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start();
    imagepng($image);

    return ob_get_clean();
};

switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/image.png':
        header('Content-Type: image/png');
        echo $png();
        break;

    case '/text-as-png':
        header('Content-Type: image/png');
        echo 'this is not an image';
        break;

    case '/large':
        header('Content-Type: image/png');
        for ($i = 0; $i < 64; $i++) {
            echo str_repeat('x', 64 * 1024);
            flush();
        }
        break;

    case '/redirect':
        header('Location: /image.png', true, 302);
        break;

    case '/redirect-private':
        header('Location: http://10.0.0.1/image.png', true, 302);
        break;

    case '/redirect-metadata':
        header('Location: http://169.254.169.254/latest/meta-data/', true, 302);
        break;

    case '/loop':
        header('Location: /loop', true, 302);
        break;

    case '/slow':
        sleep(3);
        echo $png();
        break;

    case '/missing':
        http_response_code(404);
        break;
}
