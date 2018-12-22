<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Transaction\TransactionOutputInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;

abstract class Wallet implements WalletInterface
{
    /**
     * @var DB
     */
    protected $db;

    /**
     * @var DbWallet
     */
    protected $dbWallet;

    public function __construct(DB $db, DbWallet $dbWallet)
    {
        $this->db = $db;
        $this->dbWallet = $dbWallet;
    }

    public function getConfirmedBalance(): int
    {
        return $this->db->getConfirmedBalance($this->dbWallet->getId());
    }

    public function getDbWallet(): DbWallet
    {
        return $this->dbWallet;
    }

    /**
     * @param TransactionOutputInterface[] $txOuts
     * @param int $feeRate
     * @return PreparedTx
     */
    public function send(array $txOuts, int $feeRate): PreparedTx
    {
        $totalOut = 0;
        foreach ($txOuts as $txOut) {
            $totalOut += $txOut->getValue();
        }

        $changeScript = $this->getChangeScriptGenerator()->generate();
        $txWeight = SizeEstimation::estimateWeight([], $txOuts);
        $changeOutputWeight = SizeEstimation::estimateWeight([], array_merge($txOuts, [new TransactionOutput(0, $changeScript->getScriptPubKey())])) - $txWeight;

        /** @var DbUtxo[] $utxos */
        /** @var DbScript[] $dbScripts */
        $utxos = [];
        $dbScripts = [];
        $segwit = false;
        $totalIn = 0;

        $stmt = $this->db->getPdo()->prepare("select * from utxo where walletId = ? and spentTxid IS NULL");
        $stmt->execute([
            $this->dbWallet->getId(),
        ]);

        while ($dbUtxo = $stmt->fetchObject(DbUtxo::class)) {
            /** @var DbUtxo $dbUtxo */
            $dbScript = $dbUtxo->getDbScript($this->db);
            $signData = $dbScript->getSignData();
            $rs = $signData->hasRedeemScript() ? $signData->getRedeemScript() : null;
            $ws = $signData->hasWitnessScript() ? $signData->getWitnessScript() : null;

            list ($scriptSig, $witness) = SizeEstimation::estimateUtxoFromScripts($dbScript->getScriptPubKey(), $rs, $ws);

            $inputWeight = (32+4+4+$scriptSig) * 4 + $witness;
            $markSegwit = false;
            if ($ws && !$segwit) {
                $inputWeight += 2 * 4; // two flag bytes
                $markSegwit = true;
            }

            $inputFee = (int) (ceil(($inputWeight + 3) / 4) * $feeRate);
            if ($inputFee * 3 > $dbUtxo->getValue()) {
                continue;
            }
            if ($markSegwit) {
                $segwit = true;
            }
            $utxos[] = $dbUtxo;
            $dbScripts[] = $dbScript;
            $totalIn += $dbUtxo->getValue();
            $txWeight += $inputWeight;
        }

        $totalVsize = (int)ceil(($txWeight+$changeOutputWeight + 3) / 4);
        $change = $totalIn - $totalOut - ($totalVsize * $feeRate);
        $changeOutputFee = (int)ceil(($changeOutputWeight + 3) / 4) * $feeRate;
        if ($change > $changeOutputFee/3) {
            $txOuts[] = new TransactionOutput((int) $change, $changeScript->getScriptPubKey());
        }

        if (!shuffle($txOuts)) {
            throw new \RuntimeException("txouts shuffle failed");
        }

        // shuffles keys, preserving dbScript order also
        $utxoKeys = array_keys($utxos);
        if (!shuffle($utxoKeys)) {
            throw new \RuntimeException("utxos shuffle failed");
        }

        $inputScripts = [];
        $inputTxOuts = [];
        $inputKeyIds = [];
        $builder = new TxBuilder();
        foreach ($utxoKeys as $key) {
            $builder->spendOutPoint($utxos[$key]->getOutPoint());
            $inputScripts[$key] = $dbScripts[$key]->getSignData();
            $inputKeyIds[$key] = $dbScripts[$key]->getKeyIdentifier();
            $inputTxOuts[$key] = $utxos[$key]->getTxOut();
        }

        $builder->outputs($txOuts);

        return new PreparedTx($builder->get(), $inputTxOuts, $inputScripts, $inputKeyIds);
    }

    public function sendAllCoins(ScriptInterface $destination, int $feeRate): PreparedTx
    {
        $utxos = $this->db->getUnspentWalletUtxos($this->dbWallet->getId());
        $txBuilder = new TxBuilder();
        $valueIn = 0;
        /** @var ScriptAndSignData[] $inputScripts */
        /** @var DbScript[] $dbScripts */
        $inputScripts = [];
        $dbScripts = [];
        foreach ($utxos as $utxo) {
            $txOut = $utxo->getTxOut();
            $txBuilder->spendOutPoint($utxo->getOutPoint());
            $dbScripts[] = $dbScript = $utxo->getDbScript($this->db);
            $inputScripts[] = new ScriptAndSignData($txOut->getScript(), $dbScript->getSignData());
            $valueIn += $txOut->getValue();
        }
        $estimatedVsize = SizeEstimation::estimateVsize($inputScripts, [new TransactionOutput(0, $destination)]);

        $fee = $estimatedVsize * $feeRate;
        if ($fee > $valueIn) {
            throw new \RuntimeException("Insufficient funds for fee");
        }
        $txBuilder->output($valueIn - $fee, $destination);
        return new PreparedTx($txBuilder->get(), $utxos, $dbScripts);
    }

    public function signTx(PreparedTx $prepTx): TransactionInterface
    {
        $unsignedTx = $prepTx->getTx();
        $txSigner = new Signer($unsignedTx);
        $numInputs = count($unsignedTx->getInputs());
        for ($i = 0; $i < $numInputs; $i++) {
            $txSigner
                ->input($i, $prepTx->getTxOut($i), $prepTx->getSignData($i))
                ->sign($this->getSigner($prepTx->getKeyIdentifier($i)))
            ;
        }
        return $txSigner->get();
    }
}
