<?php

declare(strict_types=1);

namespace Smartlyq;

/**
 * Error thrown for any non-2xx SmartlyQ API response (and for transport
 * failures, which carry status code 0).
 *
 * Parsed from the API error envelope:
 *   {"success": false, "error": {"code", "message", "details"}, "meta": {"request_id"}}
 */
class SmartlyQError extends \Exception
{
    private ?string $errorCode;

    /** @var array<string, mixed>|null */
    private ?array $details;

    private ?string $requestId;

    /**
     * @param int                       $statusCode HTTP status of the failed response (0 for transport failures)
     * @param array<string, mixed>|null $envelope   Decoded error envelope, when the body was parseable JSON
     * @param string                    $fallback   Message used when the envelope has none
     */
    public function __construct(
        private readonly int $statusCode,
        ?array $envelope = null,
        string $fallback = 'API error',
        ?\Throwable $previous = null,
    ) {
        $message = $envelope['error']['message'] ?? null;
        parent::__construct(is_string($message) ? $message : $fallback, $statusCode, $previous);

        $code = $envelope['error']['code'] ?? null;
        $this->errorCode = is_string($code) ? $code : null;

        $details = $envelope['error']['details'] ?? null;
        $this->details = is_array($details) ? $details : null;

        $requestId = $envelope['meta']['request_id'] ?? null;
        $this->requestId = is_string($requestId) ? $requestId : null;
    }

    /** HTTP status code of the failed response (0 for transport failures). */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Machine-readable error code from the envelope (e.g. "validation_error"), if present. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Structured error details from the envelope, if present.
     *
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** Request id from the envelope meta - include it when contacting support. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
