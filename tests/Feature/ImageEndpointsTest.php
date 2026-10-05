<?php

namespace Wink\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wink\Support\RemoteFetchException;
use Wink\Support\RemoteImageFetcher;
use Wink\Tests\TestCase;
use Wink\WinkAuthor;
use Wink\WinkPost;

class ImageEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->actingAs(WinkAuthor::create([
            'id' => (string) Str::uuid(),
            'name' => 'Author',
            'slug' => 'author',
            'email' => 'author@example.com',
            'bio' => 'Writes things.',
            'password' => bcrypt('secret'),
        ]), 'wink');
    }

    private static function jpeg(int $width = 1600, int $height = 1000): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y += 50) {
            imagefilledrectangle($image, 0, $y, $width, $y + 49, imagecolorallocate($image, ($y / 4) % 256, 90, 160));
        }

        ob_start();
        imagejpeg($image, null, 90);

        return ob_get_clean();
    }

    /**
     * Insert an EXIF segment and a comment after the JPEG's SOI marker.
     */
    private static function withMetadata(string $jpeg): string
    {
        $exif = "Exif\0\0MM\0*\0\0\0\x08\0\x01\x01\x0e\0\x02\0\0\0\x0bGPS-SECRET\0\0\0\0\0";
        $comment = 'GPS-SECRET';

        return "\xFF\xD8"
            ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
            ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
            .substr($jpeg, 2);
    }

    /**
     * A real uploaded file, so its type is detected from content (fakes
     * report the type implied by their name).
     */
    private static function upload(string $name, string $contents): UploadedFile
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'wink'), $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakeRemote(?string $body, string $error = 'The image could not be downloaded.')
    {
        $this->app->instance(RemoteImageFetcher::class, new class($body, $error) extends RemoteImageFetcher {
            private $body;

            private $error;

            public function __construct($body, $error)
            {
                $this->body = $body;
                $this->error = $error;
            }

            public function fetch(string $url): string
            {
                if ($this->body === null) {
                    throw new RemoteFetchException($this->error);
                }

                return $this->body;
            }
        });
    }

    public function test_guests_cannot_use_the_image_endpoints()
    {
        auth('wink')->logout();

        $this->postJson('/wink/api/uploads/from-url', ['url' => 'https://example.com/a.jpg'])->assertStatus(401);
        $this->postJson('/wink/api/featured-images/sources', ['url' => 'https://example.com/a.jpg'])->assertStatus(401);
        $this->postJson('/wink/api/featured-images', [])->assertStatus(401);
    }

    public function test_upload_accepts_images_and_rejects_other_files()
    {
        $this->postJson('/wink/api/uploads', ['image' => self::upload('a.jpg', self::jpeg(10, 10))])
            ->assertOk()->assertJsonStructure(['url']);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->postJson('/wink/api/uploads', ['image' => self::upload('a.svg', $svg)])
            ->assertStatus(422)->assertJsonValidationErrors('image');

        // Judged by content, not by the name it was given.
        $this->postJson('/wink/api/uploads', ['image' => self::upload('a.png', '<html><script>alert(1)</script></html>')])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_from_url_stores_the_image_untouched()
    {
        $bytes = self::jpeg(30, 20);
        $this->fakeRemote($bytes);

        $url = $this->postJson('/wink/api/uploads/from-url', ['url' => 'https://example.com/a.jpg'])->assertOk()->json('url');

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', $files[0]);
        $this->assertSame($bytes, Storage::disk('public')->get($files[0]));
        $this->assertStringEndsWith(basename($files[0]), $url);
    }

    public function test_from_url_rejects_files_that_are_not_images()
    {
        $this->fakeRemote('GIF89a not really');
        $this->postJson('/wink/api/uploads/from-url', ['url' => 'https://example.com/a.gif'])
            ->assertStatus(422)->assertJsonValidationErrors(['url' => 'Only JPEG, PNG and WebP']);

        $this->fakeRemote(null, 'Images can only be imported from public addresses.');
        $this->postJson('/wink/api/uploads/from-url', ['url' => 'http://10.0.0.1/a.jpg'])
            ->assertStatus(422)->assertJsonValidationErrors(['url' => 'public addresses']);
    }

    public function test_featured_image_flow_keeps_the_original_and_renders_webp_variants()
    {
        $original = self::withMetadata(self::jpeg());
        $this->fakeRemote($original);

        $source = $this->postJson('/wink/api/featured-images/sources', ['url' => 'https://images.example.com/photo.jpg'])
            ->assertOk()
            ->assertJson(['width' => 1600, 'height' => 1000, 'source_url' => 'https://images.example.com/photo.jpg'])
            ->json();

        // Kept untouched, privately, and served to the editor.
        $this->assertSame($original, Storage::disk('public')->get('wink/originals/'.$source['source']));
        $this->get($source['preview_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(['x' => 0, 'y' => 122, 'width' => 1600, 'height' => 693], $source['default_crop']);

        $crop = ['x' => 100, 'y' => 50, 'width' => 1200, 'height' => 520];

        $preview = $this->postJson('/wink/api/featured-images/preview', ['source' => $source['source'], 'crop' => $crop])
            ->assertOk()->json();
        $this->assertStringStartsWith('data:image/webp;base64,', $preview['graded']);
        $this->assertNotSame($preview['original'], $preview['graded']);

        $result = $this->postJson('/wink/api/featured-images', ['source' => $source['source'], 'crop' => $crop, 'grade' => true])
            ->assertOk()
            ->assertJson(['original' => $source['source'], 'meta' => ['crop' => $crop, 'graded' => true, 'width' => 1200, 'height' => 520]])
            ->json();

        $this->assertSame([800, 1200], array_keys($result['meta']['variants']));
        $this->assertSame($result['url'], $result['meta']['variants'][1200]);

        foreach ([1200 => 520, 800 => 347] as $width => $height) {
            $path = Str::after($result['meta']['variants'][$width], '/storage/');
            $info = getimagesizefromstring($bytes = Storage::disk('public')->get($path));

            $this->assertSame([$width, $height, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
            $this->assertStringNotContainsStringIgnoringCase('exif', $bytes);
            $this->assertStringNotContainsString('GPS-SECRET', $bytes);
        }
    }

    public function test_crop_boxes_are_kept_inside_the_image_and_at_the_ratio()
    {
        Storage::disk('public')->put('wink/originals/'.($source = Str::uuid().'.jpg'), self::jpeg(1000, 800));

        $this->postJson('/wink/api/featured-images', [
            'source' => $source, 'crop' => ['x' => 900, 'y' => 700, 'width' => 5000, 'height' => 10],
        ])->assertOk()->assertJson(['meta' => ['crop' => ['x' => 0, 'y' => 367, 'width' => 1000, 'height' => 433]]]);
    }

    public function test_sources_must_be_stored_originals()
    {
        foreach (['../../.env', 'x.jpg', Str::uuid().'.php'] as $source) {
            $this->postJson('/wink/api/featured-images', ['source' => $source, 'crop' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]])
                ->assertStatus(422)->assertJsonValidationErrors('source');
        }

        $this->get('/wink/api/featured-images/sources/'.Str::uuid().'.jpg')->assertNotFound();
    }

    public function test_posts_save_the_featured_image_fields_and_page_title()
    {
        $id = (string) Str::uuid();
        $author = auth('wink')->id();

        $payload = [
            'id' => $id, 'title' => 'A Post', 'slug' => 'a-post', 'author_id' => $author, 'publish_date' => '2026-10-04 10:00:00', 'published' => false, 'markdown' => false,
            'featured_image' => 'https://cdn.example.com/a-1200.webp',
            'featured_image_original' => 'abc.jpg',
            'featured_image_source_url' => 'https://www.pexels.com/photo/1',
            'featured_image_credit' => 'Photo by Someone on Pexels',
            'featured_image_alt' => 'Five gold stars',
            'featured_image_meta' => ['variants' => [1200 => 'https://cdn.example.com/a-1200.webp', 800 => 'https://cdn.example.com/a-800.webp']],
            'meta' => ['page_title' => ''],
        ];

        $this->postJson('/wink/api/posts/new', $payload)->assertOk();

        $post = WinkPost::find($id);
        $this->assertSame('A Post', $post->page_title);
        $this->assertSame('Five gold stars', $post->featured_image_alt);
        $this->assertSame('https://cdn.example.com/a-800.webp 800w, https://cdn.example.com/a-1200.webp 1200w', $post->featured_image_srcset);

        $this->postJson("/wink/api/posts/{$id}", ['meta' => ['page_title' => 'Replying to Bad Reviews']] + $payload)->assertOk();
        $this->assertSame('Replying to Bad Reviews', $post->fresh()->page_title);

        $this->postJson("/wink/api/posts/{$id}", ['featured_image_source_url' => 'javascript:alert(1)'] + $payload)
            ->assertStatus(422)->assertJsonValidationErrors('featured_image_source_url');
    }
}
