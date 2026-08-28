<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use InvalidArgumentException;

/**
 * Cloudflare accepts two kinds of credential, and they are not equivalent.
 *
 * A scoped API token can be limited to Zone:Read and DNS:Read. The legacy
 * global API key cannot be scoped at all — it can do anything the account can
 * do, including delete zones. This connector supports both because accounts
 * exist that still use the key, and it says which one is in use in every
 * discovered source so that the weaker posture is visible rather than assumed.
 */
final readonly class CloudflareCredentials
{
    public const AUTH_TOKEN = 'token';

    public const AUTH_GLOBAL_KEY = 'key';

    public function __construct(
        public string $token,
        public string $authMode = self::AUTH_TOKEN,
        public ?string $email = null,
        public ?string $accountId = null,
    ) {
        if (trim($token) === '') {
            throw new InvalidArgumentException('Cloudflare credentials require a non-empty token.');
        }

        if (! in_array($authMode, [self::AUTH_TOKEN, self::AUTH_GLOBAL_KEY], true)) {
            throw new InvalidArgumentException("[{$authMode}] is not a supported Cloudflare auth mode.");
        }

        if ($authMode === self::AUTH_GLOBAL_KEY && ($email === null || trim($email) === '')) {
            throw new InvalidArgumentException('The global API key requires the account email.');
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration): self
    {
        return new self(
            token: (string) ($configuration['token'] ?? ''),
            authMode: (string) ($configuration['auth_mode'] ?? self::AUTH_TOKEN),
            email: isset($configuration['email']) ? (string) $configuration['email'] : null,
            accountId: isset($configuration['account_id']) ? (string) $configuration['account_id'] : null,
        );
    }

    public function usesGlobalKey(): bool
    {
        return $this->authMode === self::AUTH_GLOBAL_KEY;
    }

    /**
     * A reference that identifies the account without embedding the credential.
     */
    public function accountReference(): string
    {
        return 'cloudflare:account/'.($this->accountId ?? $this->email ?? 'default');
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->usesGlobalKey()
            ? ['X-Auth-Email' => (string) $this->email, 'X-Auth-Key' => $this->token]
            : ['Authorization' => 'Bearer '.$this->token];
    }
}
