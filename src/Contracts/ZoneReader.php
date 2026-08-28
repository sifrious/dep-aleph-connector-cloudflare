<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector\Contracts;

/**
 * Everything the connector needs from Cloudflare, and nothing else.
 *
 * Two implementations exist: the live v4 client and a fixture reader a host
 * uses to exercise the whole inventory path with no credentials and no network.
 */
interface ZoneReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function zones(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function dnsRecords(string $zoneId): array;

    /**
     * Null means the setting was not observed — a missing scope, not "off".
     */
    public function sslMode(string $zoneId): ?string;
}
