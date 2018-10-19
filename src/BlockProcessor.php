<?php

declare(strict_types=1);

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\Wallet\ScriptStorage;
use BitWasp\Wallet\Wallet\UtxoStorage;

class BlockProcessor
{
    /**
     * @var DB
     */
    private $db;
    /**
     * @var ScriptStorage
     */
    private $scripts;
    /**
     * @var UtxoStorage
     */
    private $utxos;

    // cache
    private $mapScriptPubKeyToDbScript = [];
    /**
     * @var TxUpdate[]
     */
    private $txData = [];
    private $wallet = [];

    public function __construct(DB $db, DbWallet $wallet, ScriptStorage $scriptStorage, UtxoStorage $utxoStorage)
    {
        $this->db = $db;
        $this->wallet = $wallet;
        $this->scripts = $scriptStorage;
        $this->utxos = $utxoStorage;
    }

    protected function loadScript(ScriptInterface $scriptPubKey): ?DbScript
    {
        $cKey = $scriptPubKey->getBinary();
        if (array_key_exists($cKey, $this->mapScriptPubKeyToDbScript)) {
            return $this->mapScriptPubKeyToDbScript[$cKey];
        }
        if ($script = $this->scripts->searchScript($scriptPubKey)) {
            $this->mapScriptPubKeyToDbScript[$cKey] = $script;
            return $script;
        }
        return null;
    }

    public function processConfirmedTx(BufferInterface $txid, TransactionInterface $tx) {
        echo $tx->getTxId()->getHex().PHP_EOL;
        $txUpdate = new TxUpdate($txid);
        $nIn = count($tx->getInputs());
        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $txIn = $tx->getInput($iIn);
            if ($utxo = $this->utxos->search($txIn->getOutPoint())) {
                $txUpdate->inputSpendsMine($iIn, $txIn->getOutPoint(), $utxo->getTxOut());
            }
        }

        $nOut = count($tx->getOutputs());
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $txOut = $tx->getOutput($iOut);
            if ($script = $this->loadScript($txOut->getScript())) {
                $txUpdate->outputIsMine($iOut, $txOut, $script);
                echo "got script\n";
            }
        }

        if ($txUpdate->getValueChange() !== 0) {
            $this->txData[] = $txUpdate;
        }
    }

    public function commit() {
        $walletId = $this->wallet->getId();
        $this->db->getPdo()->beginTransaction();
        try {
            // commiting at the end means that chained
            // transactions are probably skipped
            // can we start tracking outpoints as they are
            // added, so the check for spends doesn't depend
            // on db state? perhaps load all consumed utxos
            // from the block, knowing there are more to come
            // as we parse the block..
            foreach ($this->txData as $update) {
                $isMine = false;
                foreach ($update->getSpends() as $spend) {
                    list ($spentByOutpoint, $outpoint) = $spend;
                    $isMine = true;
                    $this->db->deleteSpends($walletId, $outpoint, $spentByOutpoint);
                }

                if (count($update->getUtxos()) > 0) {
                    $isMine = true;
                    $this->db->createUtxos($walletId, $update->getUtxos());
                }

                if ($isMine) {
                    print_r($update);
                    $this->db->createTx($walletId, $update->getTxId(), $update->getValueChange());
                }
                echo "completed update\n";
            }
            $this->db->getPdo()->commit();
        } catch (\Exception $e) {
            echo "exception\n";
            $this->db->getPdo()->rollBack();
            throw $e;
        }
    }

    public function process(BlockInterface $block) {
        // 1. receive only wallet
        try {
            $outPointMapToTxOut = [];
            $nTx = count($block->getTransactions());
            for ($iTx = 0; $iTx < $nTx; $iTx++) {
                $tx = $block->getTransaction($iTx);
                $txId = $tx->getTxId();
                $this->processConfirmedTx($txId, $tx);
            }
            $this->commit();
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
