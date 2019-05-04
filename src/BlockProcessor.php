<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockFactory;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializerInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DbBlockTx;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\Wallet\WalletInterface;

class BlockProcessor
{
    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var TransactionSerializerInterface
     */
    private $txSerializer;

    /**
     * @var WalletInterface[]
     */
    private $wallets = [];

    /**
     * @var UtxoSet
     */
    private $utxoSet;

    public function __construct(DBInterface $db, WalletInterface... $wallets)
    {
        $this->db = $db;
        $this->txSerializer = new TransactionSerializer();
        foreach ($wallets as $wallet) {
            $this->wallets[$wallet->getDbWallet()->getId()] = $wallet;
        }
        $this->utxoSet = new DbUtxoSet($db, ...$wallets);
        //$this->utxoSet = new MemoryUtxoSet($db, new OutPointSerializer(), ...$wallets);
    }

    public function saveBlock(int $height, BufferInterface $blockHash, BufferInterface $blockData)
    {
        // 1. receive only wallet
        try {
            $this->db->saveRawBlock($blockHash, $blockData);
        } catch (\Error $e) {
            echo $e->getMessage().PHP_EOL;
            echo $e->getTraceAsString().PHP_EOL;
            sleep(20);
            die();
        } catch (\Exception $e) {
            echo $e->getMessage().PHP_EOL;
            echo $e->getTraceAsString().PHP_EOL;
            sleep(20);
            die();
        }
    }

    // called before activation, saves as rejected
    public function processConfirmedTx(int $blockHeight, string $blockHashHex, TransactionInterface $tx)
    {
//        $ins = $tx->getInputs();
//        $nIn = count($ins);
//        $valueChange = [];
//        $isCoinbase = $tx->isCoinbase();
//
//        if (!$isCoinbase) {
//            for ($iIn = 0; $iIn < $nIn; $iIn++) {
//                $outPoint = $ins[$iIn]->getOutPoint();
//                // load this utxo from wallets, update valueChange
//                $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
//                $nUtxos = count($dbUtxos);
//                for ($i = 0; $i < $nUtxos; $i++) {
//                    $dbUtxo = $dbUtxos[$i];
//                    if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
//                        $valueChange[$dbUtxo->getWalletId()] = 0;
//                    }
//                    $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
//                    echo "in: wallet {$dbUtxo->getWalletId()} value change: -{$dbUtxo->getValue()}\n";
//                }
//            }
//        }
//
//        $outs = $tx->getOutputs();
//        $nOut = count($outs);
//        for ($iOut = 0; $iOut < $nOut; $iOut++) {
//            $txOut = $outs[$iOut];
//            $walletIds = $this->utxoSet->getWalletsForScriptPubKey($txOut->getScript());
//            $numIds = count($walletIds);
//
//            for ($i = 0; $i < $numIds; $i++) {
//                $walletId = $walletIds[$i];
//                // does this allow skipping wallets which are already synced? so resync?
//                if (!array_key_exists($walletId, $this->wallets)) {
//                    continue;
//                }
//
//                $wallet = $this->wallets[$walletId];
//                $dbWallet = $wallet->getDbWallet();
//                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
//                    if (!array_key_exists($dbWallet->getId(), $valueChange)) {
//                        $valueChange[$dbWallet->getId()] = 0;
//                    }
//                    $valueChange[$dbWallet->getId()] += $txOut->getValue();
//                    echo "out: wallet $walletId value change: +{$txOut->getValue()}\n";
//                } else {
//                    throw new \RuntimeException("somehow, we didn't find the script in script storage");
//                }
//            }
//        }
//
//        if (count($valueChange) > 0) {
//            $txBin = $this->txSerializer->serialize($tx);
//            $txId = $tx->getTxId();
//            foreach ($valueChange as $walletId => $change) {
//                // note: used to be when save/activate were in same step.
//                echo "createTx:: $walletId: {$txId->getHex()} value change: $change\n";
//                if (!$this->db->createTx($walletId, $txId, $change, DbWalletTx::STATUS_REJECT, $isCoinbase, $blockHashHex, $blockHeight)) {
//                    throw new \RuntimeException("failed to create tx");
//                }
//                // need to update now because tx is stored as rejected until activated (in block accept codepath)
//            }
//
//            // save the full transaction if wallets are interested in it
//            $this->db->saveRawTx($txId, $txBin);
//        }
    }

    public function applyBlock(int $height, BufferInterface $blockHash)
    {
        $block = BlockFactory::fromBuffer(new Buffer($this->db->getRawBlock($blockHash)));
        foreach ($block->getTransactions() as $tx) {
            /** @var DbWalletTx $tx */
            $txId = $tx->getTxId();
            $isCoinbase = $tx->isCoinbase();
            $this->applyConfirmedTx($height, $blockHash, $txId, $isCoinbase, $tx);
        }
    }

    // called in Activate step
    public function applyConfirmedTx(int $height, BufferInterface $blockHash, BufferInterface $txId, bool $coinbase, TransactionInterface $tx)
    {
        $ins = $tx->getInputs();
        $nIn = count($ins);
        $valueChange = [];

        if (!$coinbase) {
            for ($iIn = 0; $iIn < $nIn; $iIn++) {
                $outPoint = $ins[$iIn]->getOutPoint();
                // load this utxo from wallets, and mark spent
                $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
                $nUtxos = count($dbUtxos);
                for ($i = 0; $i < $nUtxos; $i++) {
                    $dbUtxo = $dbUtxos[$i];
                    if (!array_key_exists($dbUtxo->getWalletId(), $this->wallets)) {
                        continue;
                    }

                    if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
                        $valueChange[$dbUtxo->getWalletId()] = 0;
                    }
                    $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
                    $this->utxoSet->spendUtxo($dbUtxo->getWalletId(), $outPoint, $txId, $iIn);
                    echo "wallet({$dbUtxo->getWalletId()}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
                }
            }
        }

        $outs = $tx->getOutputs();
        $nOut = count($outs);
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $outs[$iOut];
            $scriptWalletIds = $this->utxoSet->getWalletsForScriptPubKey($txOut->getScript());

            $numIds = count($scriptWalletIds);
            for ($i = 0; $i < $numIds; $i++) {
                $walletId = $scriptWalletIds[$i];
                // does this allow skipping wallets which are already synced? so resync?
                if (!array_key_exists($walletId, $this->wallets)) {
                    continue;
                }

                if (!array_key_exists($walletId, $valueChange)) {
                    $valueChange[$walletId] = 0;
                }
                $valueChange[$walletId] += $txOut->getValue();

                $wallet = $this->wallets[$walletId];
                $dbWallet = $wallet->getDbWallet();
                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
                    echo "wallet({$dbWallet->getId()}).newUtxo {$txId->getHex()} {$iOut} {$txOut->getValue()}\n";
                    $this->utxoSet->createUtxo($dbWallet, $script, new OutPoint($txId, $iOut), $txOut);
                }
            }
        }

        foreach ($valueChange as $walletId => $change) {
            if (!$this->db->createTx($walletId, $txId, $change, DbWalletTx::STATUS_CONFIRMED, $coinbase, $blockHash->getHex(), $height)) {
                throw new \RuntimeException("failed to update tx status");
            }
        }
    }

    public function undoBlock(BufferInterface $blockHash)
    {
        $walletIds = [];
        foreach ($this->wallets as $wallet) {
            $walletIds[] = $wallet->getDbWallet()->getId();
        }

        $txs = $this->db->fetchBlockTxs($blockHash, $walletIds);
        foreach ($txs as $tx) {
            /** @var DbBlockTx $tx */
            $this->undoConfirmedTx($tx->getTxId(), $tx->isCoinbase(), $walletIds);
        }
    }

    public function undoConfirmedTx(BufferInterface $txId, bool $isCoinbase, array $walletIds)
    {
        if (!$isCoinbase) {
            $this->db->unspendTxUtxos($txId, $walletIds);
        }
        $this->db->deleteTxUtxos($txId, $walletIds);
        foreach ($walletIds as $walletId) {
            if (!$this->db->updateTxStatus($walletId, $txId, DbWalletTx::STATUS_REJECT)) {
                throw new \RuntimeException("failed to update tx status");
            }
        }
    }
}
