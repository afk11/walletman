<?php

namespace BitWasp\Wallet;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\Wallet\ScriptStorage;

class BlockProcessor
{
    private $db;
    private $scripts;

    // cache
    private $mapScriptPubKeyToDbScript = [];

    public function __construct(DB $db, ScriptStorage $scriptStorage)
    {
        $this->db = $db;
        $this->scripts = $scriptStorage;
    }

    public function processConfirmedTx(BufferInterface $txid, TransactionInterface $tx) {
        $nIn = count($tx->getInputs());
        for ($iIn = 0; $iIn < $nIn; $iIn++) {
            $txIn = $tx->getInput($iIn);
//                            $outPointKey = $txIn->getOutPoint()->getBinary();
//                            if (array_key_exists($outPointKey, $outPointMapToTxOut) ) {
//                                throw new \RuntimeException("bad block, found duplicate input");
//                            }
//                            if (array_key_exists($outPointKey, $outPointMapToTxOut) ) {
//
//                            }
        }

        $scripts = [];
        $nOut = count($tx->getOutputs());
        for ($iOut = 0; $iOut < $nOut; $iOut++) {
            $out = $tx->getOutput($iOut);
            if ($this->scripts->searchScript($out->getScript()))
        }
        $searchScripts = implode(", ", array_fill(0, count($scripts), "?"));

        $prep = $this->db->getPdo()->prepare("SELECT * FROM script where scriptpubkey in ($searchScripts)");
        $result = $prep->execute();
        if ($prep->rowCount() > 0) {
            echo "WAT?";
        }
    }
    public function process(BlockInterface $block, int $walletId) {
        // 1. receive only wallet
        try {
            $outPointMapToTxOut = [];
            $nTx = count($block->getTransactions());
            for ($iTx = 0; $iTx < $nTx; $iTx++) {
                $tx = $block->getTransaction($iTx);
                $txId = $tx->getTxId();
                $this->processConfirmedTx($txId, $tx);
            }
        } catch (\Exception $e) {
            echo $e->getMessage().PHP_EOL;
            echo $e->getTraceAsString().PHP_EOL;
            die();
        }
    }
}
