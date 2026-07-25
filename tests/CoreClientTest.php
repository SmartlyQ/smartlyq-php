<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Smartlyq\CoreClient;
use Smartlyq\SmartlyQError;

final class CoreClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /**
     * @param list<array{status?: int, headers?: array<string, string>, body?: string}> $responses
     *        Responses are consumed in order; the last one repeats.
     * @param array<string, mixed> $options
     */
    private function client(array $responses, array $options = []): CoreClient
    {
        $this->calls = [];
        $queue = $responses;
        $transport = function (string $method, string $url, array $headers, ?string $body, float $timeout) use (&$queue): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            $response = count($queue) > 1 ? array_shift($queue) : $queue[0];

            return $response + [
                'status' => 200,
                'headers' => [],
                'body' => '{"success":true,"data":{}}',
            ];
        };

        return new CoreClient('sqk_test_xxxxxxxxxxxx', $options + ['transport' => $transport]);
    }

    public function testSendsBearerAuthAndUserAgent(): void
    {
        $client = $this->client([[]]);
        $result = $client->request('GET', '/me');

        $this->assertSame('https://api.smartlyq.com/v1/me', $this->calls[0]['url']);
        $headers = $this->calls[0]['headers'];
        $this->assertSame('Bearer sqk_test_xxxxxxxxxxxx', $headers['Authorization']);
        $this->assertSame('smartlyq-php', $headers['User-Agent']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertNull($this->calls[0]['body']);
        $this->assertSame(['success' => true, 'data' => []], $result);
    }

    public function testReadsApiKeyFromEnvironment(): void
    {
        putenv('SMARTLYQ_API_KEY=sqk_test_xxxxxxxxxxxx');
        try {
            $client = new CoreClient(null, [
                'transport' => fn (): array => ['status' => 200, 'headers' => [], 'body' => '{}'],
            ]);
            $this->assertSame([], $client->request('GET', '/me'));
        } finally {
            putenv('SMARTLYQ_API_KEY');
        }
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv('SMARTLYQ_API_KEY');
        $this->expectException(\InvalidArgumentException::class);
        new CoreClient();
    }

    public function testParsesErrorEnvelope(): void
    {
        $client = $this->client([[
            'status' => 422,
            'body' => '{"success":false,"error":{"code":"validation_error","message":"text is required",'
                . '"details":{"field":"text"}},"meta":{"request_id":"req_123"}}',
        ]], ['max_retries' => 0]);

        try {
            $client->request('POST', '/social/posts', ['body' => ['text' => '']]);
            $this->fail('Expected SmartlyQError');
        } catch (SmartlyQError $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('validation_error', $e->getErrorCode());
            $this->assertSame('text is required', $e->getMessage());
            $this->assertSame(['field' => 'text'], $e->getDetails());
            $this->assertSame('req_123', $e->getRequestId());
        }

        $this->assertCount(1, $this->calls);
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $client = $this->client([
            ['status' => 429, 'headers' => ['retry-after' => '0.01'], 'body' => '{"success":false}'],
            ['status' => 200, 'body' => '{"success":true,"data":{"ok":true}}'],
        ]);

        $result = $client->request('GET', '/me');

        $this->assertCount(2, $this->calls);
        $this->assertTrue($result['data']['ok']);
    }

    public function testDoesNotRetryOn400(): void
    {
        $client = $this->client([[
            'status' => 400,
            'body' => '{"success":false,"error":{"code":"bad_request","message":"nope"}}',
        ]]);

        try {
            $client->request('GET', '/me');
            $this->fail('Expected SmartlyQError');
        } catch (SmartlyQError $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('bad_request', $e->getErrorCode());
        }

        $this->assertCount(1, $this->calls);
    }

    public function testProfileIdAndIdempotencyKeyHeaders(): void
    {
        $client = $this->client([[]]);
        $client->request('POST', '/social/posts', [
            'body' => ['text' => 'hi'],
            'options' => ['profile_id' => 'prof_123', 'idempotency_key' => 'key-1'],
        ]);

        $headers = $this->calls[0]['headers'];
        $this->assertSame('prof_123', $headers['X-Profile-Id']);
        $this->assertSame('key-1', $headers['Idempotency-Key']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('{"text":"hi"}', $this->calls[0]['body']);
    }

    public function testEmptyBodyEncodesAsJsonObject(): void
    {
        $client = $this->client([[]]);
        $client->request('POST', '/jobs/job_123/cancel', ['body' => []]);

        $this->assertSame('{}', $this->calls[0]['body']);
    }

    public function testQueryStringSerialization(): void
    {
        $client = $this->client([[]]);
        $client->request('GET', '/social/posts', [
            'query' => ['limit' => 10, 'tags' => ['a', 'b'], 'draft' => true, 'cursor' => null],
        ]);

        $this->assertSame(
            'https://api.smartlyq.com/v1/social/posts?limit=10&tags=a&tags=b&draft=true',
            $this->calls[0]['url'],
        );
    }

    public function testCustomBaseUrl(): void
    {
        $client = $this->client([[]], ['base_url' => 'https://api.example.com/v1/']);
        $client->request('GET', '/me');

        $this->assertSame('https://api.example.com/v1/me', $this->calls[0]['url']);
    }

    public function testTransportFailureThrowsAfterRetries(): void
    {
        $attempts = 0;
        $client = new CoreClient('sqk_test_xxxxxxxxxxxx', [
            'max_retries' => 1,
            'transport' => function () use (&$attempts): array {
                $attempts++;
                throw new \RuntimeException('connection refused');
            },
        ]);

        try {
            $client->request('GET', '/me');
            $this->fail('Expected SmartlyQError');
        } catch (SmartlyQError $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame('connection_error', $e->getErrorCode());
        }

        $this->assertSame(2, $attempts);
    }
}
