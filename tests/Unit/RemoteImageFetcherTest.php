<?php

namespace Wink\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wink\Support\RemoteFetchException;
use Wink\Support\RemoteImageFetcher;

class RemoteImageFetcherTest extends TestCase
{
    private static $server;

    private static $port;

    public static function setUpBeforeClass(): void
    {
        self::$port = random_int(20000, 40000);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/../fixtures/remote-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
    }

    /**
     * A fetcher that may reach the local test server (127.0.0.1) and
     * nothing else private, with test.local resolving to it.
     */
    private function fetcher(int $maxBytes = 1048576, int $timeout = 10): RemoteImageFetcher
    {
        return new RemoteImageFetcher($timeout, $maxBytes, 3,
            function ($host) {
                return $host === 'test.local' ? ['127.0.0.1'] : RemoteImageFetcher::resolve($host);
            },
            function ($address) {
                return $address === '127.0.0.1' || RemoteImageFetcher::isPublicAddress($address);
            }
        );
    }

    private function url(string $path): string
    {
        return 'http://test.local:'.self::$port.$path;
    }

    private function assertRefused(callable $fetch, string $message)
    {
        try {
            $fetch();
            $this->fail('The fetch should have been refused.');
        } catch (RemoteFetchException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_it_downloads_an_image()
    {
        $body = $this->fetcher()->fetch($this->url('/image.png'));

        $this->assertSame("\x89PNG", substr($body, 0, 4));
    }

    public function test_it_follows_a_redirect()
    {
        $this->assertSame("\x89PNG", substr($this->fetcher()->fetch($this->url('/redirect')), 0, 4));
    }

    public function test_it_refuses_a_redirect_to_a_private_address()
    {
        $this->assertRefused(function () {
            $this->fetcher()->fetch($this->url('/redirect-private'));
        }, 'public addresses');
    }

    public function test_it_refuses_a_redirect_to_the_metadata_service()
    {
        $this->assertRefused(function () {
            $this->fetcher()->fetch($this->url('/redirect-metadata'));
        }, 'public addresses');
    }

    public function test_it_stops_after_three_redirects()
    {
        $this->assertRefused(function () {
            $this->fetcher()->fetch($this->url('/loop'));
        }, 'more than 3 times');
    }

    public function test_it_aborts_a_download_over_the_size_limit()
    {
        $this->assertRefused(function () {
            $this->fetcher(1048576)->fetch($this->url('/large'));
        }, 'larger than 1 MB');
    }

    public function test_it_times_out()
    {
        $this->assertRefused(function () {
            $this->fetcher(1048576, 1)->fetch($this->url('/slow'));
        }, 'too long');
    }

    public function test_it_reports_http_errors()
    {
        $this->assertRefused(function () {
            $this->fetcher()->fetch($this->url('/missing'));
        }, 'HTTP 404');
    }

    public function test_the_default_guard_refuses_loopback_by_name_and_address()
    {
        $fetcher = new RemoteImageFetcher(10, 1048576, 3);

        foreach (['http://127.0.0.1:'.self::$port.'/image.png', 'http://localhost:'.self::$port.'/image.png', 'http://[::1]:'.self::$port.'/image.png'] as $url) {
            $this->assertRefused(function () use ($fetcher, $url) {
                $fetcher->fetch($url);
            }, 'public addresses');
        }
    }

    /**
     * @dataProvider schemes
     */
    public function test_it_only_accepts_http_and_https($url)
    {
        $this->assertRefused(function () use ($url) {
            $this->fetcher()->fetch($url);
        }, 'Only http and https');
    }

    public static function schemes(): array
    {
        return [
            ['file:///etc/passwd'], ['ftp://example.com/a.png'], ['gopher://example.com/'],
            ['data:image/png;base64,AAAA'], ['javascript:alert(1)'], ['//example.com/a.png'], ['not a url'],
        ];
    }

    /**
     * @dataProvider addresses
     */
    public function test_it_classifies_addresses($address, $public)
    {
        $this->assertSame($public, RemoteImageFetcher::isPublicAddress($address), $address);
    }

    public static function addresses(): array
    {
        return [
            ['93.184.215.14', true], ['8.8.8.8', true], ['2606:4700:4700::1111', true],
            ['127.0.0.1', false], ['127.255.255.254', false], ['10.1.2.3', false], ['172.16.0.1', false],
            ['172.31.255.255', false], ['192.168.1.1', false], ['169.254.169.254', false], ['100.64.0.1', false],
            ['0.0.0.0', false], ['198.18.0.1', false], ['224.0.0.1', false], ['255.255.255.255', false],
            ['::', false], ['::1', false], ['fe80::1', false], ['fc00::1', false], ['fd12:3456::1', false],
            ['ff02::1', false], ['2001:db8::1', false], ['::ffff:127.0.0.1', false], ['::ffff:10.0.0.1', false],
            ['::ffff:8.8.8.8', true], ['64:ff9b::a9fe:a9fe', false], ['2002:7f00:1::', false], ['2002:808:808::', true],
            ['not-an-ip', false],
        ];
    }
}
