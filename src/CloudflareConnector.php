<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Sifrious\Aleph\Connector\ConfigurationField;
use Sifrious\Aleph\Connector\ConfigurationSchema;
use Sifrious\Aleph\Connector\Connector;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Contracts\Backfills;
use Sifrious\Aleph\Connector\Contracts\ChecksHealth;
use Sifrious\Aleph\Connector\Contracts\DiscoversSources;
use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\DiscoveredSource;
use Sifrious\Aleph\Connector\Values\DiscoveredSources;
use Sifrious\Aleph\Connector\Values\HealthReport;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;
use Sifrious\Aleph\Envelope\ExtensionMetadata;
use Sifrious\Aleph\Envelope\ObservationEnvelope;
use Sifrious\Aleph\Envelope\Provenance;
use Sifrious\CloudflareConnector\Contracts\ZoneReader;
use Throwable;

/**
 * Read-only zone and DNS observation for Cloudflare.
 *
 * Only GET requests exist in this package. A token scoped to Zone:Read and
 * DNS:Read is sufficient; anything wider is the operator's choice, and the
 * discovered source records which credential kind is in use so that a global
 * API key is visible rather than assumed away.
 */
final class CloudflareConnector implements Backfills, ChecksHealth, Connector, DiscoversSources, SyncsIncrementally
{
    public const ZONE_EXTENSION = 'cloudflare.zone';

    public const RECORD_EXTENSION = 'cloudflare.dns_record';

    public const EXTENSION_VERSION = 1;

    public const DEFAULT_BATCH = 25;

    public function __construct(
        private readonly EnvelopeSubmitter $submitter,
        private readonly Normalizer $normalizer = new Normalizer,
        private readonly ?ConnectorInstallations $installations = null,
        private readonly ?ZoneReader $reader = null,
    ) {}

    public function id(): string
    {
        return 'cloudflare';
    }

    public function name(): string
    {
        return 'Cloudflare';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function configuration(): ConfigurationSchema
    {
        return new ConfigurationSchema(
            ConfigurationField::secret('token', 'A scoped API token with Zone:Read and DNS:Read'),
            ConfigurationField::text('auth_mode', 'token (preferred) or key for the legacy global key', required: false),
            ConfigurationField::text('email', 'Account email; required only with the global key', required: false),
            ConfigurationField::text('account_id', 'Cloudflare account id, used for the source reference', required: false),
        );
    }

    public function checkHealth(): HealthReport
    {
        return HealthReport::healthy('Cloudflare connector loaded; readiness is per installation.', [
            'read_only' => true,
            'methods' => ['GET'],
            'normalizer_version' => Normalizer::VERSION,
        ]);
    }

    public function discoverSources(OperationRequest $request): DiscoveredSources
    {
        $credentials = $this->credentials($request);

        return new DiscoveredSources(new DiscoveredSource(
            $credentials->accountReference(),
            'Cloudflare ('.($credentials->accountId ?? $credentials->email ?? 'default').')',
            [
                'auth_mode' => $credentials->authMode,
                'scoped_token' => ! $credentials->usesGlobalKey(),
            ],
        ));
    }

    public function backfill(OperationRequest $request): OperationResult
    {
        return $this->ingest($request, 0);
    }

    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        return $this->ingest($request, $this->offsetFrom($request->cursor));
    }

    private function ingest(OperationRequest $request, int $offset): OperationResult
    {
        try {
            $credentials = $this->credentials($request);
            $client = $this->reader ?? new CloudflareClient($credentials);
            $zones = $client->zones();
        } catch (CloudflareError $error) {
            return OperationResult::failed($error->getMessage(), [
                'kind' => $error->kind,
                'provider_code' => $error->providerCode,
                'retryable' => $error->retryable,
            ]);
        } catch (Throwable $exception) {
            return OperationResult::failed($exception->getMessage(), ['kind' => 'unexpected']);
        }

        $batch = max(1, (int) $request->parameter('batch', self::DEFAULT_BATCH));
        $includeRecords = (bool) $request->parameter('include_records', true);
        $includeTls = (bool) $request->parameter('include_tls', true);
        /* See the Namecheap connector: Funes has no list API yet. Opt-in. */
        $returnObservations = (bool) $request->parameter('return_observations', false);
        $observations = ['zones' => [], 'records' => []];
        $installation = (string) $request->parameter('installation', 'unconfigured');
        $capturedAt = Date::now()->toDateTimeImmutable();

        $slice = array_slice($zones, $offset, $batch);
        $accepted = 0;
        $tlsUnobserved = [];

        foreach ($slice as $zone) {
            $zoneId = isset($zone['id']) ? (string) $zone['id'] : null;

            try {
                $sslMode = $includeTls && $zoneId !== null ? $client->sslMode($zoneId) : null;
            } catch (CloudflareError $error) {
                return $this->failure($error, $offset + $accepted);
            }

            $normalizedZone = $this->normalizer->zone($zone, $sslMode);

            if ($includeTls && $normalizedZone['tls_mode_observed'] === false) {
                $tlsUnobserved[] = $normalizedZone['domain'];
            }

            $outcome = $this->submit($this->zoneEnvelope($credentials, $installation, $capturedAt, $normalizedZone, $zone));

            if ($outcome !== null) {
                return $outcome;
            }

            $accepted++;

            if ($returnObservations) {
                $observations['zones'][] = $normalizedZone;
            }

            if (! $includeRecords || $zoneId === null) {
                continue;
            }

            try {
                $records = $client->dnsRecords($zoneId);
            } catch (CloudflareError $error) {
                return $this->failure($error, $offset + $accepted);
            }

            foreach ($records as $record) {
                $normalizedRecord = $this->normalizer->record((string) $normalizedZone['domain'], $record);

                $outcome = $this->submit($this->recordEnvelope(
                    $credentials,
                    $installation,
                    $capturedAt,
                    $normalizedRecord,
                    $record,
                ));

                if ($outcome !== null) {
                    return $outcome;
                }

                $accepted++;

                if ($returnObservations) {
                    $observations['records'][] = $normalizedRecord;
                }
            }
        }

        $nextOffset = $offset + count($slice);
        $metadata = [
            'zones_on_account' => count($zones),
            'zones_in_batch' => count($slice),
            'tls_mode_unobserved' => $tlsUnobserved,
            'normalizer_version' => Normalizer::VERSION,
        ];

        if ($returnObservations) {
            $metadata['observations'] = $observations;
        }

        return $nextOffset < count($zones)
            ? OperationResult::partial($accepted, (string) $nextOffset, $metadata)
            : OperationResult::completed($accepted, $metadata);
    }

    private function failure(CloudflareError $error, int $reached): OperationResult
    {
        return OperationResult::failed($error->getMessage(), [
            'kind' => $error->kind,
            'provider_code' => $error->providerCode,
            'retryable' => $error->retryable,
            'cursor' => (string) $reached,
        ]);
    }

    private function submit(ObservationEnvelope $envelope): ?OperationResult
    {
        $record = $this->submitter->submit($envelope);

        if ($record->isAuthoritative()) {
            return null;
        }

        return OperationResult::failed(sprintf(
            'Funes did not accept [%s]: %s',
            $envelope->resourceReference,
            $record->submission->error ?? $record->submission->status->value,
        ));
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $raw
     */
    private function zoneEnvelope(
        CloudflareCredentials $credentials,
        string $installation,
        DateTimeImmutable $capturedAt,
        array $normalized,
        array $raw,
    ): ObservationEnvelope {
        return new ObservationEnvelope(
            sourceReference: $credentials->accountReference(),
            sourceName: 'Cloudflare',
            resourceReference: $this->normalizer->zoneReference($normalized),
            observedAt: $capturedAt,
            payload: $this->encode($raw),
            provenance: $this->provenance($installation, $capturedAt),
            contentType: 'application/json',
            account: $credentials->accountId,
            stream: 'zones',
            eventType: 'cloudflare.zone.observed',
            providerId: $normalized['provider_id'] !== null ? (string) $normalized['provider_id'] : null,
            extensions: [new ExtensionMetadata(self::ZONE_EXTENSION, self::EXTENSION_VERSION, $normalized)],
        );
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $raw
     */
    private function recordEnvelope(
        CloudflareCredentials $credentials,
        string $installation,
        DateTimeImmutable $capturedAt,
        array $normalized,
        array $raw,
    ): ObservationEnvelope {
        return new ObservationEnvelope(
            sourceReference: $credentials->accountReference(),
            sourceName: 'Cloudflare',
            resourceReference: $this->normalizer->recordReference($normalized),
            observedAt: $capturedAt,
            payload: $this->encode($raw),
            provenance: $this->provenance($installation, $capturedAt),
            contentType: 'application/json',
            account: $credentials->accountId,
            stream: 'dns/'.$normalized['domain'],
            eventType: 'cloudflare.dns_record.observed',
            providerId: $normalized['provider_id'] !== null ? (string) $normalized['provider_id'] : null,
            extensions: [new ExtensionMetadata(self::RECORD_EXTENSION, self::EXTENSION_VERSION, $normalized)],
        );
    }

    private function provenance(string $installation, DateTimeImmutable $capturedAt): Provenance
    {
        return new Provenance(
            connectorId: $this->id(),
            connectorVersion: $this->version(),
            installationId: $installation,
            capturedAt: $capturedAt,
            details: ['normalizer_version' => Normalizer::VERSION],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function offsetFrom(?string $cursor): int
    {
        return $cursor !== null && ctype_digit($cursor) ? (int) $cursor : 0;
    }

    private function credentials(OperationRequest $request): CloudflareCredentials
    {
        $configuration = $request->parameter('configuration');

        if (is_array($configuration) && $configuration !== []) {
            return CloudflareCredentials::fromArray($configuration);
        }

        $installationId = $request->parameter('installation');

        if (is_string($installationId) && $this->installations !== null) {
            $installation = $this->installations->find($installationId);

            if ($installation !== null) {
                return CloudflareCredentials::fromArray($installation->configuration);
            }
        }

        throw CloudflareError::rejected(
            'No Cloudflare configuration was supplied; pass a configuration array or a known installation id.'
        );
    }
}
