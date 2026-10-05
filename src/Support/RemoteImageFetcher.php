<?php

namespace Wink\Support;

/**
 * Downloads a URL on an author's behalf without letting it reach internal hosts.
 *
 * Every hop is resolved up front, refused if any address is not public, and
 * then connected to that exact address so DNS cannot change between the
 * check and the request. Redirects are followed manually and re-checked.
 */
class RemoteImageFetcher
{
    /**
     * @var int
     */
    private $timeout;

    /**
     * @var int
     */
    private $maxBytes;

    /**
     * @var int
     */
    private $maxRedirects;

    /**
     * @var callable(string): string[]
     */
    private $resolver;

    /**
     * @var callable(string): bool
     */
    private $addressGuard;

    public function __construct(int $timeout, int $maxBytes, int $maxRedirects, ?callable $resolver = null, ?callable $addressGuard = null)
    {
        $this->timeout = $timeout;
        $this->maxBytes = $maxBytes;
        $this->maxRedirects = $maxRedirects;
        $this->resolver = $resolver ?: [self::class, 'resolve'];
        $this->addressGuard = $addressGuard ?: [self::class, 'isPublicAddress'];
    }

    /**
     * Build a fetcher from the wink.remote_images config.
     */
    public static function fromConfig(): self
    {
        return new self(
            (int) config('wink.remote_images.timeout', 10),
            (int) config('wink.remote_images.max_bytes', 15 * 1024 * 1024),
            (int) config('wink.remote_images.max_redirects', 3)
        );
    }

    /**
     * Download the URL and return its body.
     *
     * @throws RemoteFetchException
     */
    public function fetch(string $url): string
    {
        $deadline = microtime(true) + $this->timeout;

        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            [$status, $location, $body] = $this->request($url, $deadline);

            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = $this->resolveRedirect($url, $location);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new RemoteFetchException("The image URL responded with HTTP {$status}.");
            }

            return $body;
        }

        throw new RemoteFetchException("The image URL redirected more than {$this->maxRedirects} times.");
    }

    /**
     * Make one request, without following redirects.
     *
     * @return array{0: int, 1: string|null, 2: string}
     */
    private function request(string $url, float $deadline): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RemoteFetchException('Only http and https image URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RemoteFetchException('Image URLs may not contain credentials.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $address = $this->checkedAddress($host);

        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RemoteFetchException('The image took too long to download.');
        }

        $body = '';
        $tooLarge = false;
        $location = null;

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => $address === $host ? [] : ["{$host}:{$port}:".(strpos($address, ':') !== false ? "[{$address}]" : $address)],
            CURLOPT_TIMEOUT_MS => (int) ceil($remaining * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($remaining, 5) * 1000),
            CURLOPT_USERAGENT => 'Wink image import',
            CURLOPT_HTTPHEADER => ['Accept: image/webp,image/png,image/jpeg;q=0.9,*/*;q=0.1'],
            CURLOPT_NOPROXY => '*',
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$location) {
                if (stripos($header, 'location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > $this->maxBytes) {
                    $tooLarge = true;

                    return -1;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorCode = curl_errno($curl);

        if ($tooLarge) {
            throw new RemoteFetchException('The image is larger than '.round($this->maxBytes / 1048576).' MB.');
        }

        if ($ok === false) {
            throw new RemoteFetchException($errorCode === CURLE_OPERATION_TIMEDOUT
                ? 'The image took too long to download.'
                : 'The image could not be downloaded.');
        }

        return [$status, $location, $body];
    }

    /**
     * Resolve the host and return an address to connect to, if all are public.
     */
    private function checkedAddress(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : call_user_func($this->resolver, $host);

        if (! $addresses) {
            throw new RemoteFetchException('The image host could not be found.');
        }

        foreach ($addresses as $address) {
            if (! call_user_func($this->addressGuard, $address)) {
                throw new RemoteFetchException('Images can only be imported from public addresses.');
            }
        }

        return $addresses[0];
    }

    /**
     * Turn a Location header into an absolute URL.
     */
    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (strpos($location, '//') === 0) {
            return $parts['scheme'].':'.$location;
        }

        if (strpos($location, '/') === 0) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, strrpos($path, '/') + 1).$location;
    }

    /**
     * All IPv4 and IPv6 addresses for a host name.
     *
     * @return string[]
     */
    public static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];
        foreach ($records as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        return array_values(array_filter($addresses));
    }

    /**
     * Whether an address is publicly routable.
     */
    public static function isPublicAddress(string $address): bool
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            // IPv6 forms that embed an IPv4 address are judged by that address.
            foreach (['::ffff:0:0/96', '64:ff9b::/96', '::/96'] as $prefix) {
                if (self::inRange($binary, $prefix)) {
                    return self::isPublicAddress(inet_ntop(substr($binary, 12)));
                }
            }

            if (self::inRange($binary, '2002::/16')) {
                return self::isPublicAddress(inet_ntop(substr($binary, 2, 4)));
            }
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Private, loopback, link-local, shared, documentation, multicast and
     * otherwise reserved ranges (IANA special-purpose registries).
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '64:ff9b:1::/48', '100::/64', '2001::/23', '2001:db8::/32', '3fff::/20',
        '5f00::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * Whether a packed address falls within a CIDR range of the same family.
     */
    private static function inRange(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $network = inet_pton($network);

        if (strlen($network) !== strlen($binary)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (strncmp($binary, $network, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
