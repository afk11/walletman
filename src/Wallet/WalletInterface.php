<?php

namespace BitWasp\Wallet\Wallet;

interface WalletInterface
{
    public function getScriptGenerator(): ScriptGenerator;
    public function getChangeScriptGenerator(): ScriptGenerator;
}
