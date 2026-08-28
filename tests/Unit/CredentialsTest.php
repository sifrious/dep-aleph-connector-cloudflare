<?php

declare(strict_types=1);

use Sifrious\CloudflareConnector\CloudflareCredentials;

it('sends a bearer token by default', function (): void {
    expect(CloudflareCredentials::fromArray(cfCredentials())->headers())
        ->toBe(['Authorization' => 'Bearer not-a-real-token']);
});

it('sends the legacy header pair when the global key is used', function (): void {
    $credentials = CloudflareCredentials::fromArray(cfCredentials([
        'auth_mode' => 'key',
        'email' => 'operator@example.test',
    ]));

    expect($credentials->usesGlobalKey())->toBeTrue()
        ->and($credentials->headers())->toBe([
            'X-Auth-Email' => 'operator@example.test',
            'X-Auth-Key' => 'not-a-real-token',
        ]);
});

it('refuses the global key without an email, because Cloudflare would', function (): void {
    CloudflareCredentials::fromArray(cfCredentials(['auth_mode' => 'key']));
})->throws(InvalidArgumentException::class);

it('refuses an auth mode it does not implement', function (): void {
    CloudflareCredentials::fromArray(cfCredentials(['auth_mode' => 'oauth']));
})->throws(InvalidArgumentException::class);

it('never puts the credential in the account reference', function (): void {
    expect(CloudflareCredentials::fromArray(cfCredentials())->accountReference())
        ->toBe('cloudflare:account/acct-1')
        ->not->toContain('not-a-real-token');
});
