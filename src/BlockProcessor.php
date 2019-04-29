<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializer;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
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
     * @var WalletInterface[]
     */
    private $wallets;

    /**
     * @var UtxoSet
     */
    private $utxoSet;

    public function __construct(DBInterface $db, WalletInterface... $wallets)
    {
        $this->db = $db;
        foreach ($wallets as $wallet) {
            $this->wallets[$wallet->getDbWallet()->getId()] = $wallet;
        }
        $this->utxoSet = new DbUtxoSet($db, ...$wallets);
        //$this->utxoSet = new MemoryUtxoSet($db, new OutPointSerializer(), ...$wallets);
    }

    public function processConfirmedTx(int $blockHeight, string $blockHashHex, TransactionInterface $tx)
    {
        $txId = null;
        $getTxid = function () use (&$txId, $tx) {
            if (null === $txId) {
                $txId = $tx->getTxId();
            }
            return $txId;
        };

        $ins = $tx->getInputs();
        $nIn = count($ins);
        $valueChange = [];

        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $outPoint = $ins[$iIn]->getOutPoint();
            // load this utxo from wallets, and mark spent
            $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
            $nUtxos = count($dbUtxos);
            for ($i = 0; $i < $nUtxos; $i++) {
                $dbUtxo = $dbUtxos[$i];
                if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
                    $valueChange[$dbUtxo->getWalletId()] = 0;
                }
                $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
                $this->utxoSet->spendUtxo($dbUtxo->getWalletId(), $outPoint, $getTxid(), $iIn);
                echo "wallet({$dbUtxo->getWalletId()}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
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
                    $txId = $getTxid();
                    echo "wallet({$dbWallet->getId()}).newUtxo {$txId->getHex()} {$iOut}\n";
                    $this->utxoSet->createUtxo($dbWallet, $script, new OutPoint($txId, $iOut), $txOut);
                    if (!array_key_exists($dbWallet->getId(), $valueChange)) {
                        $valueChange[$dbWallet->getId()] = 0;
                    }
                    $valueChange[$dbWallet->getId()] += $txOut->getValue();
                }
            }
        }

        foreach ($valueChange as $walletId => $change) {
            $this->db->createTx($walletId, $txId, $change, DbWalletTx::STATUS_CONFIRMED, $blockHashHex, $blockHeight);
        }
    }

    public function unconfirm(int $height, BufferInterface $blockHash)
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

    public function process(int $height, BufferInterface $blockHash, BlockInterface $block)
    {
        $blockHashHex = $blockHash->getHex();

        // 1. receive only wallet
        try {
            $nTx = count($block->getTransactions());
            for ($iTx = 0; $iTx < $nTx; $iTx++) {
                $tx = $block->getTransaction($iTx);
                $this->processConfirmedTx($height, $blockHashHex, $tx);
            }

//            if ($height === 181) {
//                die("bail");
//            }
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
}
