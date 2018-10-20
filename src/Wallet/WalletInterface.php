<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Wallet\DB\DbWallet;

interface WalletInterface
{
    public function getDbWallet(): DbWallet;
    public function getScriptGenerator(): ScriptGenerator;
    public function getChangeScriptGenerator(): ScriptGenerator;
    public function getScriptStorage(): ScriptStorage;
}
