<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

/**
 * Turns Cloudflare's JSON into stable, comparable values.
 *
 * The same two rules as every other connector: normalization never invents, and
 * normalization is deterministic.
 */
final class Normalizer
{
    public const VERSION = 1;

    /**
     * Cloudflare's SSL setting spells Full (strict) as `strict`. Every other
     * part of this system spells it `full_strict`, so the translation happens
     * once, here, rather than in each reader.
     */
    private const TLS_MODES = [
        'off' => 'off',
        'flexible' => 'flexible',
        'full' => 'full',
        'strict' => 'full_strict',
    ];

    /**
     * @param  array<string, mixed>  $zone
     * @return array<string, mixed>
     */
    public function zone(array $zone, ?string $sslMode = null): array
    {
        $nameServers = array_values(array_map(
            fn (mixed $host): string => $this->hostname((string) $host),
            is_array($zone['name_servers'] ?? null) ? $zone['name_servers'] : [],
        ));

        sort($nameServers);

        return [
            'domain' => $this->hostname((string) ($zone['name'] ?? '')),
            'provider_id' => isset($zone['id']) ? (string) $zone['id'] : null,
            'identity_source' => isset($zone['id']) ? 'provider_id' : 'domain_name',
            'status' => isset($zone['status']) ? (string) $zone['status'] : null,
            'paused' => isset($zone['paused']) ? (bool) $zone['paused'] : null,
            'type' => isset($zone['type']) ? (string) $zone['type'] : null,
            'name_servers' => $nameServers,
            'original_registrar' => isset($zone['original_registrar']) && $zone['original_registrar'] !== null
                ? (string) $zone['original_registrar']
                : null,
            'tls_mode' => $this->tlsMode($sslMode),
            'tls_mode_observed' => $sslMode !== null,
            'account_id' => isset($zone['account']['id']) ? (string) $zone['account']['id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function record(string $domain, array $record): array
    {
        $type = strtoupper((string) ($record['type'] ?? ''));

        return [
            'domain' => $this->hostname($domain),
            'provider_id' => isset($record['id']) ? (string) $record['id'] : null,
            'type' => $type !== '' ? $type : null,
            'name' => $this->hostname((string) ($record['name'] ?? '')),
            'content' => trim((string) ($record['content'] ?? '')),
            /*
             * Cloudflare uses TTL 1 to mean "automatic". It is a sentinel, not a
             * one-second cache, and a plan that compared it numerically against
             * a real TTL would propose a change that is not a change.
             */
            'ttl' => $this->ttl($record['ttl'] ?? null),
            'ttl_automatic' => ((int) ($record['ttl'] ?? 0)) === 1,
            'priority' => isset($record['priority']) ? (int) $record['priority'] : null,
            'proxied' => isset($record['proxied']) ? (bool) $record['proxied'] : null,
            'proxiable' => isset($record['proxiable']) ? (bool) $record['proxiable'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function zoneReference(array $normalized): string
    {
        return 'cloudflare:zone/'.($normalized['provider_id'] ?? $normalized['domain']);
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function recordReference(array $normalized): string
    {
        $identifier = $normalized['provider_id']
            ?? implode('|', [
                (string) ($normalized['type'] ?? 'UNKNOWN'),
                (string) ($normalized['name'] ?? ''),
                (string) ($normalized['content'] ?? ''),
            ]);

        return 'cloudflare:record/'.$normalized['domain'].'/'.$identifier;
    }

    private function tlsMode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::TLS_MODES[strtolower(trim($value))] ?? 'unknown';
    }

    private function ttl(mixed $value): ?int
    {
        $ttl = (int) $value;

        return $ttl > 1 ? $ttl : null;
    }

    private function hostname(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.');
    }
}
