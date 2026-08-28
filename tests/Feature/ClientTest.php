<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sifrious\CloudflareConnector\CloudflareClient;
use Sifrious\CloudflareConnector\CloudflareCredentials;
use Sifrious\CloudflareConnector\CloudflareError;
use Sifrious\CloudflareConnector\RecordedSleeper;

function cfClient(?RecordedSleeper $sleeper = null, array $overrides = []): CloudflareClient
{
    return new CloudflareClient(
        CloudflareCredentials::fromArray(cfCredentials($overrides)),
        $sleeper ?? new RecordedSleeper,
        backoffMilliseconds: 100,
    );
}

it('follows pagination to the end of the account', function (): void {
    Http::fakeSequence()
        ->push(cfFixture('zones-page-1.json'), 200)
        ->push(cfFixture('zones-page-2.json'), 200);

    $zones = cfClient()->zones();

    expect($zones)->toHaveCount(3)
        ->and(array_column($zones, 'name'))->toBe(['tryin.gg', 'heynamatic.com', 'MARY.IS']);
});

it('authenticates every request with the bearer token', function (): void {
    Http::fakeSequence()
        ->push(cfFixture('zones-page-1.json'), 200)
        ->push(cfFixture('zones-page-2.json'), 200);

    cfClient()->zones();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer not-a-real-token'));
});

it('reads the dns records of a zone', function (): void {
    Http::fake(fn () => Http::response(cfFixture('dns-records.json')));

    $records = cfClient()->dnsRecords('zone-tryingg');

    expect($records)->toHaveCount(3)
        ->and($records[0]['id'])->toBe('rec-apex');
});

it('reads the ssl mode of a zone', function (): void {
    Http::fake(fn () => Http::response(cfFixture('ssl-strict.json')));

    expect(cfClient()->sslMode('zone-tryingg'))->toBe('strict');
});

it('reports an unreadable ssl setting as not observed rather than as off', function (): void {
    Http::fake(fn () => Http::response(cfFixture('error-forbidden-scope.json'), 403));

    expect(cfClient()->sslMode('zone-tryingg'))->toBeNull();
});

it('does not retry a refused credential', function (): void {
    Http::fake(fn () => Http::response(cfFixture('error-unauthorized.json'), 401));
    $sleeper = new RecordedSleeper;

    try {
        cfClient($sleeper)->zones();
        $this->fail('Expected an authentication error.');
    } catch (CloudflareError $error) {
        expect($error->kind)->toBe('unauthorized')
            ->and($error->providerCode)->toBe('10000')
            ->and($sleeper->slept)->toBeEmpty();
    }

    Http::assertSentCount(1);
});

it('names a missing scope rather than reporting a generic rejection', function (): void {
    Http::fake(fn () => Http::response(cfFixture('error-forbidden-scope.json'), 403));

    try {
        cfClient()->zones();
        $this->fail('Expected a forbidden error.');
    } catch (CloudflareError $error) {
        expect($error->kind)->toBe('forbidden')
            ->and($error->getMessage())->toContain('scope');
    }
});

it('honours Retry-After when Cloudflare throttles', function (): void {
    Http::fake(fn () => Http::response('', 429, ['Retry-After' => '2']));
    $sleeper = new RecordedSleeper;

    try {
        cfClient($sleeper)->zones();
        $this->fail('Expected a rate-limit error.');
    } catch (CloudflareError $error) {
        expect($error->kind)->toBe('rate_limited')
            ->and($error->retryAfterSeconds)->toBe(2)
            ->and($sleeper->slept)->toBe([2000, 2000]);
    }
});

it('backs off exponentially when no Retry-After is supplied', function (): void {
    Http::fake(fn () => Http::response('', 429));
    $sleeper = new RecordedSleeper;

    try {
        cfClient($sleeper)->zones();
        $this->fail('Expected a rate-limit error.');
    } catch (CloudflareError $error) {
        expect($sleeper->slept)->toBe([100, 200]);
    }
});

it('retries a server error and recovers', function (): void {
    Http::fakeSequence()
        ->push('', 502)
        ->push(cfFixture('zones-page-1.json'), 200)
        ->push(cfFixture('zones-page-2.json'), 200);

    expect(cfClient()->zones())->toHaveCount(3);
});

it('rejects a body that is not a JSON object', function (): void {
    Http::fake(fn () => Http::response('not json at all', 200, ['Content-Type' => 'text/plain']));

    try {
        cfClient()->zones();
        $this->fail('Expected a malformed-document error.');
    } catch (CloudflareError $error) {
        expect($error->kind)->toBe('malformed');
    }
});

it('treats success:false as an error even on HTTP 200', function (): void {
    Http::fake(fn () => Http::response(cfFixture('error-unauthorized.json'), 200));

    try {
        cfClient()->zones();
        $this->fail('Expected a rejection.');
    } catch (CloudflareError $error) {
        expect($error->kind)->toBe('rejected')
            ->and($error->getMessage())->toContain('Authentication error');
    }
});
