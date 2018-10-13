<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

interface WalletInterface
{
    public function getScriptGenerator(): ScriptGenerator;
    public function getChangeScriptGenerator(): ScriptGenerator;
    public function getScriptStorage(): ScriptStorage;
}
