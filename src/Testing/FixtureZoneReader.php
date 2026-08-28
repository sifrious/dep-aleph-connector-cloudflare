<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector\Testing;

use Sifrious\CloudflareConnector\Contracts\ZoneReader;

/**
 * Reads the package's own sanitized fixtures instead of Cloudflare.
 *
 * Provider shape is provider knowledge; a host that built its own fake would be
 * encoding Cloudflare rules in application code.
 */
final class FixtureZoneReader implements ZoneReader
{
    /** @var list<array<string, mixed>> */
    private array $zones;

    /** @var array<string, list<array<string, mixed>>> */
    private array $records;

    /** @var array<string, string|null> */
    private array $sslModes;

    /**
     * @param  list<array<string, mixed>>  $zones
     * @param  array<string, list<array<string, mixed>>>  $records
     * @param  array<string, string|null>  $sslModes
     */
    public function __construct(array $zones = [], array $records = [], array $sslModes = [])
    {
        $this->zones = array_values($zones);
        $this->records = $records;
        $this->sslModes = $sslModes;
    }

    /**
     * The fixture account shipped with this package: an active proxied zone, a
     * pending zone, and a paused zone whose TLS mode cannot be read.
     */
    public static function fixtureAccount(): self
    {
        $directory = dirname(__DIR__, 2).'/tests/Fixtures';
        $zones = [];

        foreach (['zones-page-1.json', 'zones-page-2.json'] as $page) {
            $payload = json_decode((string) file_get_contents($directory.'/'.$page), true);

            foreach ($payload['result'] ?? [] as $zone) {
                $zones[] = $zone;
            }
        }

        $records = json_decode((string) file_get_contents($directory.'/dns-records.json'), true);

        return new self(
            zones: $zones,
            records: ['zone-tryingg' => $records['result'] ?? []],
            sslModes: ['zone-tryingg' => 'strict', 'zone-heynamatic' => 'flexible', 'zone-maryis' => null],
        );
    }

    public function zones(): array
    {
        return $this->zones;
    }

    public function dnsRecords(string $zoneId): array
    {
        return $this->records[$zoneId] ?? [];
    }

    public function sslMode(string $zoneId): ?string
    {
        return $this->sslModes[$zoneId] ?? null;
    }
}
