<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Wallet\DB\DbScript;

interface AddressGenerator
{
    public function generate(): DbScript;
}
