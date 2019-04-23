<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializer;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\Wallet\WalletInterface;

class MemoryUtxoSet implements UtxoSet
{
    /**
     * key => DbUtxo[]
     * @var array[DbUtxo[]]
     */
    private $utxoSet = [];
    private $scriptPubKeys = [];

    /**
     * @var OutPointSerializer
     */
    private $outPointSerializer;

    /**
     * @var DBInterface
     */
    private $db;

    public function __construct(DBInterface $db, ?OutPointSerializer $serializer, WalletInterface ...$wallets)
    {
        if (null === $serializer) {
            $serializer = new OutPointSerializer();
        }
        $this->outPointSerializer = $serializer;
        $this->db = $db;
        foreach ($wallets as $wallet) {
            $walletId = $wallet->getDbWallet()->getId();
            foreach ($db->getUnspentWalletUtxos($walletId) as $dbUtxo) {
                $outPoint = $dbUtxo->getOutPoint();
                $keyBin = $this->outPointSerializer->serialize($outPoint)->getBinary();
                if (!array_key_exists($keyBin, $this->utxoSet)) {
                    $this->utxoSet[$keyBin] = [$walletId => $dbUtxo];
                } else {
                    $this->utxoSet[$keyBin][$walletId] = $dbUtxo;
                }
            }
            foreach ($this->db->getWalletScriptPubKeys($walletId) as $scriptPubKey) {
                if (!array_key_exists($scriptPubKey, $this->scriptPubKeys)) {
                    $this->scriptPubKeys[$scriptPubKey] = [$walletId];
                } else {
                    $this->scriptPubKeys[$scriptPubKey][] = $walletId;
                }
            }
        }
    }

    /**
     * @param OutPointInterface $outPoint
     * @return DbUtxo[]
     */
    public function getUtxosForOutPoint(OutPointInterface $outPoint): array
    {
        $key = $this->outPointSerializer->serialize($outPoint)->getBinary();
        if (array_key_exists($key, $this->utxoSet)) {
            return $this->utxoSet[$key];
        }
        return [];
    }

    public function spendUtxo(int $walletId, OutPointInterface $outPoint, BufferInterface $spendTxId, int $spendVout): void
    {
        $key = $this->outPointSerializer->serialize($outPoint)->getBinary();
        if (!array_key_exists($key, $this->utxoSet)) {
            throw new \LogicException("utxo");
        }
        if (!array_key_exists($walletId, $this->utxoSet[$key])) {
            throw new \LogicException("utxo walletid");
        }
        unset($this->utxoSet[$key][$walletId]);
        if (count($this->utxoSet[$key]) === 0) {
            unset($this->utxoSet[$key]);
        }
        $this->db->markUtxoSpent($walletId, $outPoint, $spendTxId, $spendVout);
    }

    /**
     * @param ScriptInterface $script
     * @return int[]
     */
    public function getWalletsForScriptPubKey(ScriptInterface $script): array
    {
        $key = $script->getHex();
        if (array_key_exists($key, $this->scriptPubKeys)) {
            return $this->scriptPubKeys[$key];
        }
        return [];
    }

    public function createUtxo(DbWallet $wallet, DbScript $script, OutPointInterface $outPoint, TransactionOutputInterface $txOut): void
    {
        $this->db->createUtxo($wallet, $script, $outPoint, $txOut);
        $utxo = $this->db->searchUnspentUtxo($wallet->getId(), $outPoint);
        $key = $this->outPointSerializer->serialize($outPoint)->getBinary();
        if (!array_key_exists($key, $this->utxoSet)) {
            $this->utxoSet[$key] = [$wallet->getId() => $utxo];
        } else {
            $this->utxoSet[$key][$wallet->getId()] = $utxo;
        }
    }
}
