<?php

declare(strict_types=1);

namespace Smartlyq;

/**
 * SmartlyQ SDK core HTTP client.
 *
 * Hand-written; the resource classes in src/Resources/ (and the SmartlyQ
 * facade) are generated on top of it by scripts/generate-client.php.
 */
class CoreClient
{
    public const DEFAULT_BASE_URL = 'https://api.smartlyq.com/v1';

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;

    /** @var array<string, string> */
    private array $defaultHeaders;

    /**
     * Transport callable, injectable for testing. Signature:
     *   fn (string $method, string $url, array<string,string> $headers, ?string $body, float $timeout)
     *     : array{status: int, headers: array<string, string>, body: string}
     * Response header names must be lowercase.
     *
     * @var callable
     */
    private $transport;

    /**
     * @param string|null $apiKey SmartlyQ API key (sqk_live_... or sqk_test_...).
     *                            Falls back to the SMARTLYQ_API_KEY environment variable.
     * @param array{
     *     base_url?: string,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     default_headers?: array<string, string>,
     *     transport?: callable
     * } $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey ??= getenv('SMARTLYQ_API_KEY') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \InvalidArgumentException(
                'Missing API key. Pass an apiKey to the client or set the SMARTLYQ_API_KEY environment variable.'
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 60);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->defaultHeaders = $options['default_headers'] ?? [];
        $this->transport = $options['transport'] ?? $this->curlTransport(...);
    }

    /**
     * Sends a request and returns the decoded JSON response as an associative array.
     *
     * @param array{
     *     body?: array<array-key, mixed>,
     *     query?: array<string, mixed>,
     *     options?: array{
     *         headers?: array<string, string>,
     *         profile_id?: string,
     *         idempotency_key?: string,
     *         timeout?: int|float
     *     }
     * } $req
     * @return array<array-key, mixed>
     * @throws SmartlyQError on any non-2xx response or transport failure
     */
    public function request(string $method, string $path, array $req = []): array
    {
        $opts = $req['options'] ?? [];
        $url = $this->buildUrl($path, $req['query'] ?? []);

        $headers = array_merge(
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => 'smartlyq-php',
            ],
            $this->defaultHeaders,
            $opts['headers'] ?? [],
        );

        $body = null;
        if (array_key_exists('body', $req)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode(
                $req['body'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            // PHP encodes an empty array as [], but API request bodies are objects.
            if ($body === '[]') {
                $body = '{}';
            }
        }
        if (!empty($opts['profile_id'])) {
            $headers['X-Profile-Id'] = (string) $opts['profile_id'];
        }
        if (!empty($opts['idempotency_key'])) {
            $headers['Idempotency-Key'] = (string) $opts['idempotency_key'];
        }

        $timeout = (float) ($opts['timeout'] ?? $this->timeout);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = ($this->transport)($method, $url, $headers, $body, $timeout);
            } catch (\Throwable $e) {
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoff($attempt));
                    continue;
                }
                throw new SmartlyQError(
                    0,
                    ['error' => ['code' => 'connection_error', 'message' => 'Request failed: ' . $e->getMessage()]],
                    'Request failed',
                    $e,
                );
            }

            $status = (int) ($response['status'] ?? 0);
            $responseHeaders = array_change_key_case($response['headers'] ?? [], CASE_LOWER);
            $responseBody = (string) ($response['body'] ?? '');

            if ($status >= 200 && $status < 300) {
                if ($status === 204 || $responseBody === '') {
                    return [];
                }
                $decoded = json_decode($responseBody, true);

                return is_array($decoded) ? $decoded : [];
            }

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < $this->maxRetries) {
                $retryAfter = (float) ($responseHeaders['retry-after'] ?? 0);
                $this->sleep($retryAfter > 0 ? $retryAfter : $this->backoff($attempt));
                continue;
            }

            $envelope = json_decode($responseBody, true);
            throw new SmartlyQError($status, is_array($envelope) ? $envelope : null, 'HTTP ' . $status);
        }
    }

    /** @param array<string, mixed> $query */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($this->stringifyQueryValue($item));
            }
        }

        return $pairs === [] ? $url : $url . '?' . implode('&', $pairs);
    }

    private function stringifyQueryValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Default transport built on ext-curl.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curlTransport(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('cURL error: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $responseBody];
    }

    /** Exponential backoff with jitter, in seconds. */
    private function backoff(int $attempt): float
    {
        $base = 0.5 * (2 ** $attempt);

        return $base + (mt_rand() / mt_getrandmax()) * $base * 0.25;
    }

    private function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }
}
