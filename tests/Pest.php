<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Sifrious\CloudflareConnector\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

function cfFixture(string $name): string
{
    return file_get_contents(__DIR__.'/Fixtures/'.$name) ?: '';
}

/**
 * @return array<string, string>
 */
function cfCredentials(array $overrides = []): array
{
    return array_replace([
        'token' => 'not-a-real-token',
        'auth_mode' => 'token',
        'account_id' => 'acct-1',
    ], $overrides);
}
