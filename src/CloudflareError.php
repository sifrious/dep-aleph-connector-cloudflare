<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use RuntimeException;

final class CloudflareError extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?string $providerCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $detail): self
    {
        return new self("Cloudflare could not be reached: {$detail}", 'transport', null, true);
    }

    public static function rateLimited(string $detail, ?int $retryAfter = null): self
    {
        return new self("Cloudflare rate limited this token: {$detail}", 'rate_limited', null, true, $retryAfter);
    }

    public static function unauthorized(string $detail, ?string $code = null): self
    {
        return new self("Cloudflare refused the credential: {$detail}", 'unauthorized', $code, false);
    }

    public static function forbidden(string $detail, ?string $code = null): self
    {
        return new self(
            "Cloudflare refused the request; the token is probably missing a scope: {$detail}",
            'forbidden',
            $code,
            false,
        );
    }

    public static function rejected(string $detail, ?string $code = null): self
    {
        return new self("Cloudflare rejected the request: {$detail}", 'rejected', $code, false);
    }

    public static function malformed(string $detail): self
    {
        return new self("Cloudflare returned a document this connector cannot read: {$detail}", 'malformed', null, false);
    }
}
