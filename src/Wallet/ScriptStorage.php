<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

// abstract way of querying
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Wallet\DB\DbScript;

interface ScriptStorage
{
    public function searchScript(ScriptInterface $script): ?DbScript;
}
