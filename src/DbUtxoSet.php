<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\Wallet\WalletInterface;

class DbUtxoSet implements UtxoSet
{

    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var WalletInterface
     */
    private $wallets;

    public function __construct(DBInterface $db, WalletInterface ...$wallets)
    {
        $this->db = $db;
        foreach ($wallets as $wallet) {
            $walletId = $wallet->getDbWallet()->getId();
            $this->wallets[$walletId] = $walletId;
        }
    }

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getUtxosForOutPoint(OutPointInterface $outPoint): array
    {
        return $this->db->getWalletUtxosWithUnspentUtxo($outPoint);
    }

    /**
     * @param ScriptInterface $script
     * @return int[]
     */
    public function getWalletsForScriptPubKey(ScriptInterface $script): array
    {

    }

    public function createUtxo(DbWallet $wallet, DbScript $script, OutPointInterface $outPoint, TransactionOutputInterface $txOut): void
    {
        $this->db->createUtxo($wallet->getId(), $script->getId(), $outPoint, $txOut);
    }

    /**
     * @param int $walletId
     * @param OutPointInterface $outPoint
     * @param BufferInterface $spendTxId
     * @param int $spendVout
     * @throws \Exception
     */
    public function spendUtxo(int $walletId, OutPointInterface $outPoint, BufferInterface $spendTxId, int $spendVout): void
    {
        $this->db->markUtxoSpent($walletId, $outPoint, $spendTxId, $spendVout);
    }

    public function undoTx(BufferInterface $txId, bool $isCoinbase, int $walletId)
    {
        if (!$isCoinbase) {
            $this->db->unspendTxUtxos($txId, [$walletId]);
        }
        $this->db->deleteTxUtxos($txId, [$walletId]);
        if (!$this->db->updateTxStatus($walletId, $txId, DbWalletTx::STATUS_REJECT)) {
            throw new \RuntimeException("failed to update tx status");
        }
    }
}
