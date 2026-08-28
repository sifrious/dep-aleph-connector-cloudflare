<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector;

use Sifrious\CloudflareConnector\Contracts\Sleeper;

/**
 * Records the backoff a run would have waited without waiting for it.
 */
final class RecordedSleeper implements Sleeper
{
    /** @var list<int> */
    public array $slept = [];

    public function sleep(int $milliseconds): void
    {
        $this->slept[] = $milliseconds;
    }
}
