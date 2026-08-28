<?php

declare(strict_types=1);

use Sifrious\CloudflareConnector\Normalizer;

it('translates Cloudflare strict into the full_strict everything else uses', function (): void {
    $normalizer = new Normalizer;

    expect($normalizer->zone(['name' => 'a.test'], 'strict')['tls_mode'])->toBe('full_strict')
        ->and($normalizer->zone(['name' => 'a.test'], 'flexible')['tls_mode'])->toBe('flexible')
        ->and($normalizer->zone(['name' => 'a.test'], 'full')['tls_mode'])->toBe('full')
        ->and($normalizer->zone(['name' => 'a.test'], 'off')['tls_mode'])->toBe('off')
        ->and($normalizer->zone(['name' => 'a.test'], 'something_new')['tls_mode'])->toBe('unknown');
});

it('distinguishes a TLS mode that was not observed from one that is off', function (): void {
    $unobserved = (new Normalizer)->zone(['name' => 'a.test'], null);
    $off = (new Normalizer)->zone(['name' => 'a.test'], 'off');

    expect($unobserved['tls_mode'])->toBeNull()
        ->and($unobserved['tls_mode_observed'])->toBeFalse()
        ->and($off['tls_mode'])->toBe('off')
        ->and($off['tls_mode_observed'])->toBeTrue();
});

it('sorts nameservers so an unchanged delegation never looks changed', function (): void {
    $one = (new Normalizer)->zone(['name' => 'a.test', 'name_servers' => ['b.ns.test', 'a.ns.test']]);
    $two = (new Normalizer)->zone(['name' => 'a.test', 'name_servers' => ['a.ns.test', 'B.NS.TEST.']]);

    expect($one['name_servers'])->toBe(['a.ns.test', 'b.ns.test'])
        ->and($two['name_servers'])->toBe($one['name_servers']);
});

it('treats TTL 1 as automatic rather than as one second', function (): void {
    $automatic = (new Normalizer)->record('a.test', ['type' => 'A', 'name' => 'a.test', 'ttl' => 1]);
    $explicit = (new Normalizer)->record('a.test', ['type' => 'A', 'name' => 'a.test', 'ttl' => 3600]);

    expect($automatic['ttl'])->toBeNull()
        ->and($automatic['ttl_automatic'])->toBeTrue()
        ->and($explicit['ttl'])->toBe(3600)
        ->and($explicit['ttl_automatic'])->toBeFalse();
});

it('lowercases names and keeps proxy state as a tri-state', function (): void {
    $normalizer = new Normalizer;

    $proxied = $normalizer->record('TRYIN.GG', ['type' => 'a', 'name' => 'WWW.Tryin.GG.', 'proxied' => true]);
    $silent = $normalizer->record('tryin.gg', ['type' => 'A', 'name' => 'tryin.gg']);

    expect($proxied['name'])->toBe('www.tryin.gg')
        ->and($proxied['type'])->toBe('A')
        ->and($proxied['proxied'])->toBeTrue()
        ->and($silent['proxied'])->toBeNull();
});

it('produces identical output for identical input', function (): void {
    $zone = ['id' => 'z', 'name' => 'a.test', 'name_servers' => ['b.ns.test', 'a.ns.test'], 'status' => 'active'];

    expect((new Normalizer)->zone($zone, 'strict'))->toBe((new Normalizer)->zone($zone, 'strict'));
});

it('says which basis a zone identity rests on', function (): void {
    $normalizer = new Normalizer;

    $withId = $normalizer->zone(['id' => 'zone-1', 'name' => 'a.test']);
    $without = $normalizer->zone(['name' => 'a.test']);

    expect($normalizer->zoneReference($withId))->toBe('cloudflare:zone/zone-1')
        ->and($without['identity_source'])->toBe('domain_name')
        ->and($normalizer->zoneReference($without))->toBe('cloudflare:zone/a.test');
});
