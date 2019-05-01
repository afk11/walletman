<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializerInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
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

    public function saveBlock(int $height, BufferInterface $blockHash, BlockInterface $block)
    {
        $blockHashHex = $blockHash->getHex();

        // 1. receive only wallet
        try {
            $nTx = count($block->getTransactions());
            for ($iTx = 0; $iTx < $nTx; $iTx++) {
                $tx = $block->getTransaction($iTx);
                $this->processConfirmedTx($height, $blockHashHex, $tx);
            }
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
        $ins = $tx->getInputs();
        $nIn = count($ins);
        $valueChange = [];

        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $outPoint = $ins[$iIn]->getOutPoint();
            // load this utxo from wallets, update valueChange
            $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
            $nUtxos = count($dbUtxos);
            for ($i = 0; $i < $nUtxos; $i++) {
                $dbUtxo = $dbUtxos[$i];
                if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
                    $valueChange[$dbUtxo->getWalletId()] = 0;
                }
                $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
            }
        }

        $outs = $tx->getOutputs();
        $nOut = count($outs);
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $outs[$iOut];
            $walletIds = $this->utxoSet->getWalletsForScriptPubKey($txOut->getScript());
            $numIds = count($walletIds);

            for ($i = 0; $i < $numIds; $i++) {
                $walletId = $walletIds[$i];
                // does this allow skipping wallets which are already synced? so resync?
                if (!array_key_exists($walletId, $this->wallets)) {
                    continue;
                }

                $wallet = $this->wallets[$walletId];
                $dbWallet = $wallet->getDbWallet();
                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
                    if (!array_key_exists($dbWallet->getId(), $valueChange)) {
                        $valueChange[$dbWallet->getId()] = 0;
                    }
                    $valueChange[$dbWallet->getId()] += $txOut->getValue();
                } else {
                    throw new \RuntimeException("somehow, we didn't find the script in script storage");
                }
            }
        }

        if (count($valueChange) > 0) {
            $txBin = $this->txSerializer->serialize($tx);
            $txId = $tx->getTxId();
            foreach ($valueChange as $walletId => $change) {
                // note: used to be when save/activate were in same step.
                $this->db->createTx($walletId, $txId, $change, DbWalletTx::STATUS_REJECT, $blockHashHex, $blockHeight);
                // need to update now because tx is stored as rejected until activated (in block accept codepath)
            }

            // save the full transaction if wallets are interested in it
            $this->db->saveRawTx($txId, $txBin);
        }
    }

    public function applyBlock(BufferInterface $blockHash)
    {
        $txs = $this->db->fetchBlockTxs($blockHash, array_keys($this->wallets));
        $rawTxs = [];
        foreach ($txs as $tx) {
            /** @var DbWalletTx $tx */
            $txId = $tx->getTxId();
            $binTxId = $txId->getBinary();
            if (array_key_exists($binTxId, $rawTxs)) {
                $rawTx = $rawTxs[$binTxId];
            } else {
                $rawTxBin = $this->db->getRawTx($txId);
                $rawTx = $rawTxs[$binTxId] = $this->txSerializer->parse(new Buffer($rawTxBin));
            }
            $this->applyConfirmedTx($txId, $rawTx);
        }
    }

    // called in Activate step
    public function applyConfirmedTx(BufferInterface $txId, TransactionInterface $tx)
    {
        $ins = $tx->getInputs();
        $nIn = count($ins);
        $walletIds = [];

        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $outPoint = $ins[$iIn]->getOutPoint();
            // load this utxo from wallets, and mark spent
            $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
            $nUtxos = count($dbUtxos);
            for ($i = 0; $i < $nUtxos; $i++) {
                $dbUtxo = $dbUtxos[$i];
                $walletIds[$dbUtxo->getWalletId()] = 1;
                $this->utxoSet->spendUtxo($dbUtxo->getWalletId(), $outPoint, $txId, $iIn);
                echo "wallet({$dbUtxo->getWalletId()}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
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

                $walletIds[$walletId] = 1;
                $wallet = $this->wallets[$walletId];
                $dbWallet = $wallet->getDbWallet();
                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
                    echo "wallet({$dbWallet->getId()}).newUtxo {$txId->getHex()} {$iOut} {$txOut->getValue()}\n";
                    $this->utxoSet->createUtxo($dbWallet, $script, new OutPoint($txId, $iOut), $txOut);
                }
            }
        }

        foreach (array_keys($walletIds) as $walletId) {
            if (!$this->db->updateTxStatus($walletId, $txId, DbWalletTx::STATUS_CONFIRMED)) {
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
            /** @var DbWalletTx $tx */
            // tx may have spent some utxos, and
            // created some utxos. undo these.
            $txId = $tx->getTxId();
            $this->utxoSet->undoTx($txId, $tx->getWalletId());
        }
    }
}
