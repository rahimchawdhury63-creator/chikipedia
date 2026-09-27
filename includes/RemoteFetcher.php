<?php
declare(strict_types=1);

final class RemoteFetchException extends RuntimeException {}

/**
 * HTTPS-only remote reader with DNS pinning, private-network blocking, bounded
 * redirects, strict size limits, and MIME validation. This is used by imports;
 * it must never become a general-purpose open proxy.
 */
final class RemoteFetcher
{
    public function fetch(string $url, array $allowedMimePrefixes, int $maxBytes = 2097152, ?string $pinnedHost = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RemoteFetchException('Remote imports require the PHP cURL extension.');
        }
        $maxBytes = max(1024, min(16 * 1024 * 1024, $maxBytes));
        $current = $url;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            [$current, $host, $ip] = $this->validateAndResolve($current);
            if($pinnedHost!==null&&$host!==mb_strtolower(rtrim($pinnedHost,'.')))throw new RemoteFetchException('The remote endpoint redirected outside its approved host.');
            $body = '';
            $headers = [];
            $tooLarge = false;
            $curl = curl_init($current);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => SITE_NAME . '/1.0 (' . SITE_URL . '; remote encyclopedia import)',
                CURLOPT_RESOLVE => [$host . ':443:' . $ip],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                    $length = strlen($line);
                    $line = trim($line);
                    if ($line !== '' && str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[mb_strtolower(trim($name))] = trim($value);
                    }
                    return $length;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $mime = mb_strtolower(trim(explode(';', (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE))[0]));
            $error = curl_error($curl);
            curl_close($curl);
            if ($ok === false) {
                if ($tooLarge) {
                    throw new RemoteFetchException('The remote resource exceeds the safe import size limit.');
                }
                throw new RemoteFetchException($error !== '' ? $error : 'The remote resource could not be downloaded.');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true) && isset($headers['location'])) {
                $current = $this->resolveRedirect($current, $headers['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RemoteFetchException('The remote server returned HTTP ' . $status . '.');
            }
            $allowed = false;
            foreach ($allowedMimePrefixes as $prefix) {
                if (str_starts_with($mime, mb_strtolower($prefix))) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new RemoteFetchException('The remote resource type is not allowed (' . ($mime ?: 'unknown') . ').');
            }
            return ['body' => $body, 'content_type' => $mime, 'final_url' => $current, 'http_status' => $status];
        }
        throw new RemoteFetchException('The remote resource redirected too many times.');
    }

    public function fetchJson(string $url, int $maxBytes = 2097152, ?string $pinnedHost = null): array
    {
        $response = $this->fetch($url, ['application/json', 'application/problem+json'], $maxBytes, $pinnedHost);
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RemoteFetchException('The remote API did not return a JSON object.');
        }
        return $decoded;
    }

    private function validateAndResolve(string $url): array
    {
        $url = trim($url);
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new RemoteFetchException('The remote URL is malformed or too long.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || mb_strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new RemoteFetchException('Only complete HTTPS URLs can be imported.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RemoteFetchException('Credentials and custom ports are not allowed in remote URLs.');
        }
        $host = mb_strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RemoteFetchException('Private network hosts cannot be imported.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new RemoteFetchException('The remote hostname could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RemoteFetchException('Private or reserved network addresses cannot be imported.');
            }
        }
        $normalized = 'https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return [$normalized, $host, $ips[0]];
    }

    private function resolveRedirect(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\\\\]/', $location)) {
            throw new RemoteFetchException('The remote server returned an invalid redirect.');
        }
        if (preg_match('~^https://~i', $location)) {
            return $location;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            throw new RemoteFetchException('Remote redirects must remain on HTTPS.');
        }
        $baseParts = parse_url($base);
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $origin = 'https://' . $baseParts['host'];
        if (str_starts_with($location, '?')) {
            return $origin . ($baseParts['path'] ?? '/') . $location;
        }
        if (str_starts_with($location, '#')) {
            return $origin . ($baseParts['path'] ?? '/') . (isset($baseParts['query']) ? '?' . $baseParts['query'] : '');
        }
        $redirect = parse_url($location);
        $redirectPath = (string) ($redirect['path'] ?? '');
        $path = str_starts_with($redirectPath, '/')
            ? $redirectPath
            : rtrim(str_replace('\\', '/', dirname($baseParts['path'] ?? '/')), '/') . '/' . $redirectPath;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $origin . '/' . implode('/', $segments) . (isset($redirect['query']) ? '?' . $redirect['query'] : '');
    }
}
