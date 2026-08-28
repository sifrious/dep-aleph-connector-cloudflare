<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sifrious\Aleph\Connector\Capability;
use Sifrious\Aleph\Connector\ConnectorDispatcher;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\CloudflareConnector\CloudflareConnector;
use Sifrious\CloudflareConnector\Normalizer;

function cfRequest(array $parameters = []): OperationRequest
{
    return new OperationRequest(
        'cloudflare:account/acct-1',
        array_replace(['configuration' => cfCredentials(), 'installation' => 'install-1'], $parameters),
    );
}

/**
 * One full read: two zone pages, then ssl + records for each of the three zones.
 */
function fakeCloudflareAccount(int $reads = 1): void
{
    $sequence = Http::fakeSequence();

    for ($read = 0; $read < $reads; $read++) {
        $sequence
            ->push(cfFixture('zones-page-1.json'), 200)
            ->push(cfFixture('zones-page-2.json'), 200)
            ->push(cfFixture('ssl-strict.json'), 200)
            ->push(cfFixture('dns-records.json'), 200)
            ->push(cfFixture('ssl-flexible.json'), 200)
            ->push(cfFixture('dns-records-empty.json'), 200)
            ->push(cfFixture('ssl-strict.json'), 200)
            ->push(cfFixture('dns-records-empty.json'), 200);
    }
}

it('registers itself with the capabilities the ticket requires', function (): void {
    $dispatcher = app(ConnectorDispatcher::class);

    expect($dispatcher->supports('cloudflare', Capability::DiscoversSources))->toBeTrue()
        ->and($dispatcher->supports('cloudflare', Capability::Backfills))->toBeTrue()
        ->and($dispatcher->supports('cloudflare', Capability::SyncsIncrementally))->toBeTrue()
        ->and($dispatcher->supports('cloudflare', Capability::ChecksHealth))->toBeTrue();
});

it('declares the account as its source and says which credential kind is in use', function (): void {
    Http::fake();

    $scoped = app(CloudflareConnector::class)->discoverSources(cfRequest());
    $global = app(CloudflareConnector::class)->discoverSources(cfRequest([
        'configuration' => cfCredentials(['auth_mode' => 'key', 'email' => 'operator@example.test']),
    ]));

    expect($scoped->sources[0]->metadata['scoped_token'])->toBeTrue()
        ->and($global->sources[0]->metadata['scoped_token'])->toBeFalse();

    Http::assertNothingSent();
});

it('keeps the token out of the configuration schema values', function (): void {
    expect(app(CloudflareConnector::class)->configuration()->secrets())->toBe(['token'])
        ->and(app(CloudflareConnector::class)->configuration()->required())->toBe(['token']);
});

it('accepts every zone and record on the account', function (): void {
    fakeCloudflareAccount();

    $result = app(CloudflareConnector::class)->backfill(cfRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->complete)->toBeTrue()
        ->and($result->records)->toBe(6)
        ->and($result->metadata['zones_on_account'])->toBe(3)
        ->and($result->metadata['normalizer_version'])->toBe(Normalizer::VERSION);
});

it('issues only GET requests', function (): void {
    fakeCloudflareAccount();

    app(CloudflareConnector::class)->backfill(cfRequest());

    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

it('names the zones whose TLS mode it could not read', function (): void {
    Http::fakeSequence()
        ->push(cfFixture('zones-page-1.json'), 200)
        ->push(cfFixture('zones-page-2.json'), 200)
        ->push(cfFixture('error-forbidden-scope.json'), 403)
        ->push(cfFixture('dns-records.json'), 200)
        ->push(cfFixture('ssl-strict.json'), 200)
        ->push(cfFixture('dns-records-empty.json'), 200)
        ->push(cfFixture('ssl-strict.json'), 200)
        ->push(cfFixture('dns-records-empty.json'), 200);

    $result = app(CloudflareConnector::class)->backfill(cfRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->metadata['tls_mode_unobserved'])->toBe(['tryin.gg']);
});

it('skips the TLS call entirely when it is not asked for', function (): void {
    Http::fakeSequence()
        ->push(cfFixture('zones-page-1.json'), 200)
        ->push(cfFixture('zones-page-2.json'), 200)
        ->push(cfFixture('dns-records.json'), 200)
        ->push(cfFixture('dns-records-empty.json'), 200)
        ->push(cfFixture('dns-records-empty.json'), 200);

    $result = app(CloudflareConnector::class)->backfill(cfRequest(['include_tls' => false]));

    expect($result->successful)->toBeTrue();

    Http::assertSentCount(5);
});

it('checkpoints a partial run and resumes from the cursor', function (): void {
    fakeCloudflareAccount(3);

    $first = app(CloudflareConnector::class)->backfill(cfRequest(['batch' => 1]));

    expect($first->successful)->toBeTrue()
        ->and($first->complete)->toBeFalse()
        ->and($first->cursor)->toBe('1')
        ->and($first->records)->toBe(4);
});

it('reports a refused credential as a structured failure', function (): void {
    Http::fake(fn () => Http::response(cfFixture('error-unauthorized.json'), 401));

    $result = app(CloudflareConnector::class)->backfill(cfRequest());

    expect($result->successful)->toBeFalse()
        ->and($result->metadata['kind'])->toBe('unauthorized')
        ->and($result->metadata['retryable'])->toBeFalse();
});

it('fails clearly when no configuration was supplied', function (): void {
    Http::fake();

    $result = app(CloudflareConnector::class)->backfill(new OperationRequest('cloudflare:account/acct-1'));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('No Cloudflare configuration');
});

it('reports readiness without contacting the provider', function (): void {
    Http::fake();

    expect(app(CloudflareConnector::class)->checkHealth()->healthy)->toBeTrue();

    Http::assertNothingSent();
});

it('produces the same accepted count when the same account is read twice', function (): void {
    fakeCloudflareAccount(2);

    $first = app(CloudflareConnector::class)->backfill(cfRequest());
    $second = app(CloudflareConnector::class)->backfill(cfRequest());

    expect($first->successful)->toBeTrue()
        ->and($second->records)->toBe($first->records);
});

it('runs the whole inventory from fixtures with no network and no credentials', function (): void {
    Http::fake();

    $connector = new CloudflareConnector(
        app(Sifrious\Aleph\Envelope\EnvelopeSubmitter::class),
        new Normalizer,
        null,
        Sifrious\CloudflareConnector\Testing\FixtureZoneReader::fixtureAccount(),
    );

    $result = $connector->backfill(cfRequest());

    expect($result->successful)->toBeTrue()
        ->and($result->complete)->toBeTrue()
        ->and($result->records)->toBe(6)
        ->and($result->metadata['tls_mode_unobserved'])->toBe(['mary.is']);

    Http::assertNothingSent();
});
