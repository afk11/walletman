<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Wallet\DB\DbWallet;

interface WalletInterface
{
    public function getDbWallet(): DbWallet;
    public function getConfirmedBalance(): int;
    public function getScriptGenerator(): ScriptGenerator;
    public function getChangeScriptGenerator(): ScriptGenerator;
    public function getScriptStorage(): ScriptStorage;

    public function isLocked(): bool;
    public function lockWallet();

    /**
     * @param TransactionOutputInterface[] $txOuts
     * @param int $feeRate
     * @return PreparedTx
     */
    public function send(array $txOuts, int $feeRate): PreparedTx;
    public function sendAllCoins(ScriptInterface $destination, int $feeRate): PreparedTx;
    public function signTx(PreparedTx $prepTx): TransactionInterface;
}
