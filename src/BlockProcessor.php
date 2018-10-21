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
    private $start;

    public function __construct(DB $db, WalletInterface... $wallets)
    {
        $this->db = $db;
        $this->wallets = $wallets;
        $this->start = microtime(true);
    }

    private function getTxKey(BufferInterface $txid): string
    {
        return $txid->getHex();
    }

    public function processConfirmedTx(BufferInterface $txid, TransactionInterface $tx)
    {
        if (array_key_exists($txid->getHex(), $this->txMap)) {
            throw new \LogicException();
        }
        $nIn = count($tx->getInputs());
        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $input = $tx->getInput($iIn);
            $inputTx = null;
            $inputTxId = $this->getTxKey($input->getOutPoint()->getTxId());
            if (array_key_exists($inputTxId, $this->txMap)) {
                $this->txMap[$inputTxId]->spendOutput((int) $input->getOutPoint()->getVout(), new OutPoint($txid, $iIn));
            }
        }

        $nOut = count($tx->getOutputs());
        $outputs = [];
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $tx->getOutput($iOut);
            $outputs[] = new Utxo(new OutPoint($txid, $iOut), $txOut, null);
        }

        $this->txMap[$this->getTxKey($txid)] = new Tx($txid, $tx, $outputs);
    }

    public function commit(int $blockHeight)
    {
        $numTx = count($this->txMap);
        $txIds = array_keys($this->txMap);
        for ($i = 0; $i < $numTx; $i++) {
            $txWorkload = $this->txMap[$txIds[$i]];
            $txId = Buffer::hex($txIds[$i]);
            $tx = $txWorkload->getTx();
            $nIn = count($tx->getInputs());
            $valueChange = [];

            for ($iIn = 0; $iIn < $nIn; $iIn++) {
                $outPoint = $tx->getInput($iIn)->getOutPoint();
                $inputTxId = $this->getTxKey($outPoint->getTxId());
                // mark spent. need wallet ids for balance update.

                if (array_key_exists($inputTxId, $this->txMap)) {
                    // txin was created in this block, so output information is available
                    $fundTx = $this->txMap[$inputTxId];
                    $spentBy = $fundTx->getOutputs()[$outPoint->getVout()]->getSpentOutPoint();
                    assert($spentBy !== null);
                }

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
