<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\Block\Tx;
use BitWasp\Wallet\Block\Utxo;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\Wallet\WalletInterface;

class BlockProcessor
{
    /**
     * @var DB
     */
    private $db;

    /**
     * @var Tx[]
     */
    private $txMap = [];

    /**
     * @var WalletInterface[]
     */
    private $wallets;

    public function __construct(DB $db, WalletInterface... $wallets)
    {
        $this->db = $db;
        $this->wallets = $wallets;
    }

    public function processConfirmedTx(BufferInterface $txid, TransactionInterface $tx)
    {
        $thisTxKey = $txid->getBinary();
        if (array_key_exists($thisTxKey, $this->txMap)) {
            throw new \LogicException();
        }
        $ins = $tx->getInputs();
        $nIn = count($ins);
        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $outPoint = $ins[$iIn]->getOutPoint();
            $inputTxId = $outPoint->getTxId()->getBinary();
            if (array_key_exists($inputTxId, $this->txMap)) {
                $this->txMap[$inputTxId]->spendOutput($outPoint->getVout(), new OutPoint($txid, $iIn));
            }
        }

        $outs = $tx->getOutputs();
        $nOut = count($outs);
        $utxos = [];
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $utxos[] = new Utxo(new OutPoint($txid, $iOut), $outs[$iOut], null);
        }

        $this->txMap[$thisTxKey] = new Tx($txid, $tx, $utxos);
    }

    public function commit(int $blockHeight)
    {
        $numTx = count($this->txMap);
        $txIds = array_keys($this->txMap);
        for ($i = 0; $i < $numTx; $i++) {
            $txWorkload = $this->txMap[$txIds[$i]];
            $txId = new Buffer($txIds[$i]);
            $tx = $txWorkload->getTx();
            $nIn = count($tx->getInputs());
            $valueChange = [];

            for ($iIn = 0; $iIn < $nIn; $iIn++) {
                $outPoint = $tx->getInput($iIn)->getOutPoint();
                // load this utxo from wallets, and mark spent
                $dbUtxos = $this->db->getWalletUtxosWithUnspentUtxo($outPoint);
                foreach ($dbUtxos as $dbUtxo) {
                    if (!array_key_exists($dbUtxo->getWalletId(), $valueChange)) {
                        $valueChange[$dbUtxo->getWalletId()] = 0;
                    }
                    $valueChange[$dbUtxo->getWalletId()] -= $dbUtxo->getValue();
                    $this->db->deleteSpends($dbUtxo->getWalletId(), $outPoint, $txId, $iIn);
                    echo "wallet({$dbUtxo->getWalletId()}).utxoSpent {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}\n";
                }
            }

            $nOut = count($tx->getOutputs());
            $txUtxos = $txWorkload->getOutputs();
            for ($iOut = 0; $iOut < $nOut; $iOut++) {
                $txOut = $tx->getOutput($iOut);
                foreach ($this->wallets as $walletIdx => $wallet) {
                    $dbWallet = $wallet->getDbWallet();
                    if (($script = $wallet->getScriptStorage()->searchScript($txOut->getScript()))) {
                        echo "wallet({$dbWallet->getId()}).newUtxo {$txId->getHex()} {$iOut}\n";
                        $this->db->createUtxo($dbWallet, $script, $txUtxos[$iOut]);
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
            $this->commit($height);
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
