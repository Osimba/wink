<?php

namespace Wink\Tests\Feature;

use Wink\Images\FeaturedImageProcessor;
use Wink\Tests\TestCase;

class FeaturedImagePipelineTest extends TestCase
{
    /**
     * Mean RGB of the reference script's output for the fixture, from
     * `python3 tests/fixtures/generate_reference.py path/to/grade.py`.
     *
     * TODO: fill these in once tests/fixtures/featured-fixture.jpg (the Pexels
     * stars photo) is added. The test is skipped until then.
     */
    private const EXPECTED_MEAN_RGB = null; // e.g. [123.45, 120.10, 118.02]

    public function test_the_pipeline_matches_the_reference_mean_rgb_within_two()
    {
        $fixture = __DIR__.'/../fixtures/featured-fixture.jpg';

        if (! file_exists($fixture) || self::EXPECTED_MEAN_RGB === null) {
            $this->markTestSkipped('Add tests/fixtures/featured-fixture.jpg and its expected mean RGB.');
        }

        $bytes = file_get_contents($fixture);
        $processor = FeaturedImageProcessor::fromConfig();
        $size = $processor->dimensions($bytes);
        $crop = $processor->defaultCrop($size['width'], $size['height']);

        $webp = $processor->render($bytes, $crop, true)['images'][1200];

        foreach (self::meanRgb($webp) as $channel => $mean) {
            $this->assertEqualsWithDelta(self::EXPECTED_MEAN_RGB[$channel], $mean, 2, "Channel {$channel}");
        }
    }

    private static function meanRgb(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        $sums = [0, 0, 0];

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $color = imagecolorat($image, $x, $y);
                $sums[0] += ($color >> 16) & 0xFF;
                $sums[1] += ($color >> 8) & 0xFF;
                $sums[2] += $color & 0xFF;
            }
        }

        $count = imagesx($image) * imagesy($image);

        return array_map(function ($sum) use ($count) {
            return $sum / $count;
        }, $sums);
    }
}
