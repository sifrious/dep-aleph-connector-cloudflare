<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Sifrious\CloudflareConnector\Contracts\Sleeper;

/**
 * Read-only Cloudflare REST v4 reader.
 *
 * Cloudflare returns HTTP 200 with `"success": false` for application errors,
 * so the envelope is asserted before anything inside it is read — the same
 * discipline the Namecheap connector applies to its XML status attribute.
 *
 * Only GET is issued. The method is the enforcement: there is no code path here
 * that builds a POST, PUT, PATCH or DELETE, so a scoped read token is
 * sufficient and a global key is never exercised beyond reading.
 */
final class CloudflareClient
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly CloudflareCredentials $credentials,
        private readonly Sleeper $sleeper = new RealSleeper,
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMilliseconds = 500,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function zones(): array
    {
        return $this->paginate('/zones', []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dnsRecords(string $zoneId): array
    {
        return $this->paginate("/zones/{$zoneId}/dns_records", ['per_page' => 100]);
    }

    /**
     * The zone's SSL setting.
     *
     * A token scoped to Zone:Read and DNS:Read cannot always read zone
     * settings. That is a missing scope, not a missing setting, so it comes
     * back null and the caller records "not observed" rather than "off".
     */
    public function sslMode(string $zoneId): ?string
    {
        try {
            $payload = $this->get("/zones/{$zoneId}/settings/ssl", []);
        } catch (CloudflareError $error) {
            if ($error->kind === 'forbidden' || $error->kind === 'unauthorized') {
                return null;
            }

            throw $error;
        }

        $value = $payload['result']['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query): array
    {
        $items = [];
        $page = 1;

        do {
            $payload = $this->get($path, array_merge(
                ['page' => $page, 'per_page' => self::PAGE_SIZE],
                $query,
            ));

            foreach ($payload['result'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $totalPages = (int) ($payload['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= max(1, $totalPages));

        return $items;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attempt($path, $query);
            } catch (CloudflareError $error) {
                if (! $error->retryable || $attempt >= $this->maxAttempts) {
                    throw $error;
                }

                $this->sleeper->sleep(
                    $error->retryAfterSeconds !== null
                        ? $error->retryAfterSeconds * 1000
                        : $this->backoffMilliseconds * (2 ** ($attempt - 1)),
                );
            }
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function attempt(string $path, array $query): array
    {
        try {
            $response = Http::withHeaders($this->credentials->headers())
                ->timeout($this->timeoutSeconds)
                ->get(self::BASE.$path, $query);
        } catch (ConnectionException $exception) {
            throw CloudflareError::transport($exception->getMessage());
        }

        return $this->interpret($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(Response $response): array
    {
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw CloudflareError::rateLimited(
                'HTTP 429.',
                $retryAfter !== '' && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($response->serverError()) {
            throw CloudflareError::transport('HTTP '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw CloudflareError::malformed('The response body was not a JSON object.');
        }

        if (($payload['success'] ?? false) === true) {
            return $payload;
        }

        [$message, $code] = $this->firstError($payload);

        return match (true) {
            $response->status() === 401 => throw CloudflareError::unauthorized($message, $code),
            $response->status() === 403 => throw CloudflareError::forbidden($message, $code),
            default => throw CloudflareError::rejected($message, $code),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string|null}
     */
    private function firstError(array $payload): array
    {
        $errors = $payload['errors'] ?? [];
        $first = is_array($errors) && $errors !== [] ? reset($errors) : null;

        if (! is_array($first)) {
            return ['no error detail supplied', null];
        }

        return [
            (string) ($first['message'] ?? 'no error detail supplied'),
            isset($first['code']) ? (string) $first['code'] : null,
        ];
    }
}
