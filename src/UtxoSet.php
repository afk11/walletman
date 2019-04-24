<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;

interface UtxoSet
{
    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getUtxosForOutPoint(OutPointInterface $outPoint): array;

    public function spendUtxo(int $walletId, OutPointInterface $outPoint, BufferInterface $spendTxId, int $spendVout): void;

    /**
     * @param ScriptInterface $script
     * @return int[]
     */
    public function getWalletsForScriptPubKey(ScriptInterface $script): array;

    public function createUtxo(DbWallet $wallet, DbScript $script, OutPointInterface $outPoint, TransactionOutputInterface $txOut): void;

}
