<?php

declare(strict_types=1);

namespace FpmDoctor\Recommend;

use FpmDoctor\Config\Pool;
use FpmDoctor\Rss\RssMeasurement;
use FpmDoctor\Status\FpmStatus;

final class PoolInput
{
    public function __construct(
        public readonly Pool $pool,
        public readonly RssMeasurement $rss,
        public readonly ?FpmStatus $status = null,
    ) {
    }
}
