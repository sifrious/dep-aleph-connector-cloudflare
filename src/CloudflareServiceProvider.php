<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use Illuminate\Support\ServiceProvider;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\ConnectorRegistry;
use Sifrious\Aleph\Envelope\EnvelopeSubmitter;

final class CloudflareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Normalizer::class);

        $this->app->singleton(CloudflareConnector::class, fn ($app): CloudflareConnector => new CloudflareConnector(
            $app->make(EnvelopeSubmitter::class),
            $app->make(Normalizer::class),
            $app->make(ConnectorInstallations::class),
        ));
    }

    public function boot(): void
    {
        $this->app->make(ConnectorRegistry::class)
            ->register($this->app->make(CloudflareConnector::class));
    }
}
