<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializer;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DBInterface;
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
        //$this->utxoSet = new DbUtxoSet($db, ...$wallets);
        $this->utxoSet = new MemoryUtxoSet($db, new OutPointSerializer(), ...$wallets);
    }

    public function processConfirmedTx(BufferInterface $txId, TransactionInterface $tx)
    {
        $nIn = count($tx->getInputs());
        $valueChange = [];

        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $outPoint = $tx->getInput($iIn)->getOutPoint();
            // load this utxo from wallets, and mark spent
            $dbUtxos = $this->utxoSet->getUtxosForOutPoint($outPoint);
            $nUtxos = count($dbUtxos);
            for ($i = 0; $i < $nUtxos; $i++) {
                $dbUtxo = $dbUtxos[$i];
                if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
                    $valueChange[$dbUtxo->getWalletId()] = 0;
                }
                $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
                $this->utxoSet->spendUtxo($dbUtxo->getWalletId(), $outPoint, $txId, $iIn);
                echo "wallet({$dbUtxo->getWalletId()}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
            }
        }

        $nOut = count($tx->getOutputs());
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $tx->getOutput($iOut);
            $walletIds = $this->utxoSet->getWalletsForScriptPubKey($txOut->getScript());
            $numIds = count($walletIds);
            for ($i = 0; $i < $numIds; $i++) {
                $walletId = $walletIds[$i];
                if (!array_key_exists($walletId, $this->wallets)) {
                    continue;
                }

                $wallet = $this->wallets[$walletId];
                $dbWallet = $wallet->getDbWallet();
                if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
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
            $this->db->createTx($walletId, $txId, $change);
        }
    }

    public function process(int $height, BlockInterface $block)
    {
        // 1. receive only wallet
        try {
            $nTx = count($block->getTransactions());
            for ($iTx = 0; $iTx < $nTx; $iTx++) {
                $tx = $block->getTransaction($iTx);
                $txId = $tx->getTxId();
                $this->processConfirmedTx($txId, $tx);
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
