<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\NativeResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * A portable implementation of the HttpClientInterface contracts based on PHP stream wrappers.
 *
 * PHP stream wrappers are able to fetch response bodies concurrently,
 * but each request is opened synchronously.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @experimental in 4.3
 */
final class NativeHttpClient implements HttpClientInterface, LoggerAwareInterface
{
    use HttpClientTrait;
    use LoggerAwareTrait;

    private $defaultOptions = self::OPTIONS_DEFAULTS;
    private $multi;

    /**
     * @param array $defaultOptions     Default requests' options
     * @param int   $maxHostConnections The maximum number of connections to open
     *
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     */
    public function __construct(array $defaultOptions = [], int $maxHostConnections = 6)
    {
        if ($defaultOptions) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $defaultOptions, self::OPTIONS_DEFAULTS);
        }

        // Use an internal stdClass object to share state between the client and its responses
        $this->multi = (object) [
            'openHandles' => [],
            'handlesActivity' => [],
            'pendingResponses' => [],
            'maxHostConnections' => 0 < $maxHostConnections ? $maxHostConnections : PHP_INT_MAX,
            'responseCount' => 0,
            'dnsCache' => [],
            'handles' => [],
            'sleep' => false,
            'id' => random_int(PHP_INT_MIN, PHP_INT_MAX),
        ];
    }

    /**
     * @see HttpClientInterface::OPTIONS_DEFAULTS for available options
     *
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$url, $options] = self::prepareRequest($method, $url, $options, $this->defaultOptions);

        if ($options['bindto'] && file_exists($options['bindto'])) {
            throw new TransportException(__CLASS__.' cannot bind to local Unix sockets, use e.g. CurlHttpClient instead.');
        }

        $options['body'] = self::getBodyAsString($options['body']);

        if ('' !== $options['body'] && 'POST' === $method && !isset($options['headers']['content-type'])) {
            $options['request_headers'][] = 'content-type: application/x-www-form-urlencoded';
        }

        if ($gzipEnabled = \extension_loaded('zlib') && !isset($options['headers']['accept-encoding'])) {
            // gzip is the most widely available algo, no need to deal with deflate
            $options['request_headers'][] = 'accept-encoding: gzip';
        }

        if ($options['peer_fingerprint']) {
            if (isset($options['peer_fingerprint']['pin-sha256']) && 1 === \count($options['peer_fingerprint'])) {
                throw new TransportException(__CLASS__.' cannot verify "pin-sha256" fingerprints, please provide a "sha256" one.');
            }

            unset($options['peer_fingerprint']['pin-sha256']);
        }

        $info = [
            'response_headers' => [],
            'url' => $url,
            'error' => null,
            'http_method' => $method,
            'http_code' => 0,
            'redirect_count' => 0,
            'start_time' => 0.0,
            'fopen_time' => 0.0,
            'connect_time' => 0.0,
            'redirect_time' => 0.0,
            'starttransfer_time' => 0.0,
            'total_time' => 0.0,
            'namelookup_time' => 0.0,
            'size_upload' => 0,
            'size_download' => 0,
            'size_body' => \strlen($options['body']),
            'primary_ip' => '',
            'primary_port' => 'http:' === $url['scheme'] ? 80 : 443,
        ];

        if ($onProgress = $options['on_progress']) {
            // Memoize the last progress to ease calling the callback periodically when no network transfer happens
            $lastProgress = [0, 0];
            $onProgress = static function (...$progress) use ($onProgress, &$lastProgress, &$info) {
                $progressInfo = $info;
                $progressInfo['url'] = implode('', $info['url']);
                unset($progressInfo['fopen_time'], $progressInfo['size_body']);

                if ($progress && -1 === $progress[0]) {
                    // Response completed
                    $lastProgress[0] = max($lastProgress);
                } else {
                    $lastProgress = $progress ?: $lastProgress;
                }

                $onProgress($lastProgress[0], $lastProgress[1], $progressInfo);
            };
        }

        // Always register a notification callback to compute live stats about the response
        $notification = static function (int $code, int $severity, ?string $msg, int $msgCode, int $dlNow, int $dlSize) use ($onProgress, &$info) {
            $now = microtime(true);
            $info['total_time'] = $now - $info['start_time'];

            if (STREAM_NOTIFY_PROGRESS === $code) {
                $info['size_upload'] += $dlNow ? 0 : $info['size_body'];
                $info['size_download'] = $dlNow;
            } elseif (STREAM_NOTIFY_CONNECT === $code) {
                $info['connect_time'] += $now - $info['fopen_time'];
            } else {
                return;
            }

            if ($onProgress) {
                $onProgress($dlNow, $dlSize);
            }
        };

        if ($options['resolve']) {
            $this->multi->dnsCache = $options['resolve'] + $this->multi->dnsCache;
        }

        $this->logger && $this->logger->info(sprintf('Request: %s %s', $method, implode('', $url)));

        [$host, $port, $url['authority']] = self::dnsResolve($url, $this->multi, $info, $onProgress);

        if (!isset($options['headers']['host'])) {
            $options['request_headers'][] = 'host: '.$host.$port;
        }

        $context = [
            'http' => [
                'protocol_version' => $options['http_version'] ?: '1.1',
                'method' => $method,
                'content' => $options['body'],
                'ignore_errors' => true,
                'user_agent' => 'Symfony HttpClient/Native',
                'curl_verify_ssl_peer' => $options['verify_peer'],
                'curl_verify_ssl_host' => $options['verify_host'],
                'auto_decode' => false, // Disable dechunk filter, it's incompatible with stream_select()
                'timeout' => $options['timeout'],
                'follow_location' => false, // We follow redirects ourselves - the native logic is too limited
            ],
            'ssl' => array_filter([
                'peer_name' => $host,
                'verify_peer' => $options['verify_peer'],
                'verify_peer_name' => $options['verify_host'],
                'cafile' => $options['cafile'],
                'capath' => $options['capath'],
                'local_cert' => $options['local_cert'],
                'local_pk' => $options['local_pk'],
                'passphrase' => $options['passphrase'],
                'ciphers' => $options['ciphers'],
                'peer_fingerprint' => $options['peer_fingerprint'],
                'capture_peer_cert_chain' => $options['capture_peer_cert_chain'],
                'allow_self_signed' => (bool) $options['peer_fingerprint'],
                'SNI_enabled' => true,
                'disable_compression' => true,
            ], static function ($v) { return null !== $v; }),
            'socket' => [
                'bindto' => $options['bindto'],
                'tcp_nodelay' => true,
            ],
        ];

        $proxy = self::getProxy($options['proxy'], $url);
        $noProxy = $_SERVER['no_proxy'] ?? $_SERVER['NO_PROXY'] ?? '';
        $noProxy = $noProxy ? preg_split('/[\s,]+/', $noProxy) : [];

        $resolveRedirect = self::createRedirectResolver($options, $host, $proxy, $noProxy, $info, $onProgress);
        $context = stream_context_create($context, ['notification' => $notification]);
        self::configureHeadersAndProxy($context, $host, $options['request_headers'], $proxy, $noProxy);

        return new NativeResponse($this->multi, $context, implode('', $url), $options, $gzipEnabled, $info, $resolveRedirect, $onProgress, $this->logger);
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof NativeResponse) {
            $responses = [$responses];
        } elseif (!\is_iterable($responses)) {
            throw new \TypeError(sprintf('%s() expects parameter 1 to be an iterable of NativeResponse objects, %s given.', __METHOD__, \is_object($responses) ? \get_class($responses) : \gettype($responses)));
        }

        return new ResponseStream(NativeResponse::stream($responses, $timeout));
    }

    private static function getBodyAsString($body): string
    {
        if (\is_resource($body)) {
            return stream_get_contents($body);
        }

        if (!$body instanceof \Closure) {
            return $body;
        }

        $result = '';

        while ('' !== $data = $body(self::$CHUNK_SIZE)) {
            if (!\is_string($data)) {
                throw new TransportException(sprintf('Return value of the "body" option callback must be string, %s returned.', \gettype($data)));
            }

            $result .= $data;
        }

        return $result;
    }

    /**
     * Loads proxy configuration from the same environment variables as curl when no proxy is explicitly set.
     */
    private static function getProxy(?string $proxy, array $url): ?array
    {
        if (null === $proxy) {
            // Ignore HTTP_PROXY except on the CLI to work around httpoxy set of vulnerabilities
            $proxy = $_SERVER['http_proxy'] ?? (\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) ? $_SERVER['HTTP_PROXY'] ?? null : null) ?? $_SERVER['all_proxy'] ?? $_SERVER['ALL_PROXY'] ?? null;

            if ('https:' === $url['scheme']) {
                $proxy = $_SERVER['https_proxy'] ?? $_SERVER['HTTPS_PROXY'] ?? $proxy;
            }
        }

        if (null === $proxy) {
            return null;
        }

        $proxy = (parse_url($proxy) ?: []) + ['scheme' => 'http'];

        if (!isset($proxy['host'])) {
            throw new TransportException('Invalid HTTP proxy: host is missing.');
        }

        if ('http' === $proxy['scheme']) {
            $proxyUrl = 'tcp://'.$proxy['host'].':'.($proxy['port'] ?? '80');
        } elseif ('https' === $proxy['scheme']) {
            $proxyUrl = 'ssl://'.$proxy['host'].':'.($proxy['port'] ?? '443');
        } else {
            throw new TransportException(sprintf('Unsupported proxy scheme "%s": "http" or "https" expected.', $proxy['scheme']));
        }

        return [
            'url' => $proxyUrl,
            'auth' => isset($proxy['user']) ? 'Basic '.base64_encode(rawurldecode($proxy['user']).':'.rawurldecode($proxy['pass'] ?? '')) : null,
        ];
    }

    /**
     * Resolves the IP of the host using the local DNS cache if possible.
     */
    private static function dnsResolve(array $url, \stdClass $multi, array &$info, ?\Closure $onProgress): array
    {
        if ($port = parse_url($url['authority'], PHP_URL_PORT) ?: '') {
            $info['primary_port'] = $port;
            $port = ':'.$port;
        } else {
            $info['primary_port'] = 'http:' === $url['scheme'] ? 80 : 443;
        }

        $host = parse_url($url['authority'], PHP_URL_HOST);

        if (null === $ip = $multi->dnsCache[$host] ?? null) {
            $now = microtime(true);

            if (!$ip = gethostbynamel($host)) {
                throw new TransportException(sprintf('Could not resolve host "%s".', $host));
            }

            $info['namelookup_time'] += microtime(true) - $now;
            $multi->dnsCache[$host] = $ip = $ip[0];
        }

        $info['primary_ip'] = $ip;

        if ($onProgress) {
            // Notify DNS resolution
            $onProgress();
        }

        return [$host, $port, substr_replace($url['authority'], $ip, -\strlen($host) - \strlen($port), \strlen($host))];
    }

    /**
     * Handles redirects - the native logic is too buggy to be used.
     */
    private static function createRedirectResolver(array $options, string $host, ?array $proxy, array $noProxy, array &$info, ?\Closure $onProgress): \Closure
    {
        $redirectHeaders = [];
        if (0 < $maxRedirects = $options['max_redirects']) {
            $redirectHeaders = ['host' => $host];
            $redirectHeaders['with_auth'] = $redirectHeaders['no_auth'] = array_filter($options['request_headers'], static function ($h) {
                return 0 !== stripos($h, 'Host:');
            });

            if (isset($options['headers']['authorization']) || isset($options['headers']['cookie'])) {
                $redirectHeaders['no_auth'] = array_filter($options['request_headers'], static function ($h) {
                    return 0 !== stripos($h, 'Authorization:') && 0 !== stripos($h, 'Cookie:');
                });
            }
        }

        return static function (\stdClass $multi, ?string $location, $context) use ($redirectHeaders, $proxy, $noProxy, &$info, $maxRedirects, $onProgress): ?string {
            if (null === $location || $info['http_code'] < 300 || 400 <= $info['http_code']) {
                $info['redirect_url'] = null;

                return null;
            }

            $url = self::resolveUrl(self::parseUrl($location), $info['url']);
            $info['redirect_url'] = implode('', $url);

            if ($info['redirect_count'] >= $maxRedirects) {
                return null;
            }

            $now = microtime(true);
            $info['url'] = $url;
            ++$info['redirect_count'];
            $info['redirect_time'] = $now - $info['start_time'];

            // Do like curl and browsers: turn POST to GET on 301, 302 and 303
            if (\in_array($info['http_code'], [301, 302, 303], true)) {
                $options = stream_context_get_options($context)['http'];

                if ('POST' === $options['method'] || 303 === $info['http_code']) {
                    $info['http_method'] = $options['method'] = 'HEAD' === $options['method'] ? 'HEAD' : 'GET';
                    $options['content'] = '';
                    $options['header'] = array_filter($options['header'], static function ($h) {
                        return 0 !== stripos($h, 'Content-Length:') && 0 !== stripos($h, 'Content-Type:');
                    });

                    stream_context_set_option($context, ['http' => $options]);
                }
            }

            [$host, $port, $url['authority']] = self::dnsResolve($url, $multi, $info, $onProgress);
            stream_context_set_option($context, 'ssl', 'peer_name', $host);

            if (false !== (parse_url($location, PHP_URL_HOST) ?? false)) {
                // Authorization and Cookie headers MUST NOT follow except for the initial host name
                $requestHeaders = $redirectHeaders['host'] === $host ? $redirectHeaders['with_auth'] : $redirectHeaders['no_auth'];
                $requestHeaders[] = 'host: '.$host.$port;
                self::configureHeadersAndProxy($context, $host, $requestHeaders, $proxy, $noProxy);
            }

            return implode('', $url);
        };
    }

    private static function configureHeadersAndProxy($context, string $host, array $requestHeaders, ?array $proxy, array $noProxy)
    {
        if (null === $proxy) {
            return stream_context_set_option($context, 'http', 'header', $requestHeaders);
        }

        // Matching "no_proxy" should follow the behavior of curl

        foreach ($noProxy as $rule) {
            $dotRule = '.'.ltrim($rule, '.');

            if ('*' === $rule || $host === $rule || substr($host, -\strlen($dotRule)) === $dotRule) {
                return stream_context_set_option($context, 'http', 'header', $requestHeaders);
            }
        }

        stream_context_set_option($context, 'http', 'proxy', $proxy['url']);
        stream_context_set_option($context, 'http', 'request_fulluri', true);

        if (null !== $proxy['auth']) {
            $requestHeaders[] = 'Proxy-Authorization: '.$proxy['auth'];
        }

        return stream_context_set_option($context, 'http', 'header', $requestHeaders);
    }
}
