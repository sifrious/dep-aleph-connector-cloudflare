<?php

declare(strict_types=1);

namespace Sifrious\CloudflareConnector\Contracts;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
