<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Wallet\DB\DbScript;

interface ScriptGenerator
{
    public function generate(): DbScript;
}
