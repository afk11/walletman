<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\Factory\Signer;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWallet;

class Bip44Wallet extends Wallet
{
    const INDEX_EXTERNAL = 0;
    const INDEX_CHANGE = 1;

    /**
     * @var DbKey
     */
    private $dbKey;

    /**
     * @var NetworkInterface
     */
    private $network;

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var int
     */
    private $gapLimit;

    /**
     * @var HierarchicalKey
     */
    private $accountPrivateKey;

    public function __construct(DB $db, DbWallet $wallet, DbKey $dbKey, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        parent::__construct($db, $wallet);

        if ($dbKey->getDepth() !== 3) {
            throw new \RuntimeException("invalid key depth for bip44 account, should provide M/purpose'/coinType'/account'");
        }
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("invalid key for bip44 account, should be a branch node");
        }

        $this->gapLimit = 100;
        $this->dbKey = $dbKey;
        $this->network = $network;
        $this->ecAdapter = $ecAdapter;
    }


    protected function getExternalScriptPath(): string
    {
        return $this->dbKey->getPath() . "/" . self::INDEX_EXTERNAL;
    }

    protected function getChangeScriptPath(): string
    {
        return $this->dbKey->getPath() . "/" . self::INDEX_CHANGE;
    }

    protected function getGeneratorForPath(string $path): ScriptGenerator
    {
        $branchNode = $this->db->loadKeyByPath($this->dbKey->getWalletId(), $path, 0);
        $key = $branchNode->getHierarchicalKey($this->network, $this->ecAdapter);
        return new Bip32Generator($this->db, $branchNode, $this->gapLimit, $key);
    }

    public function getScriptStorage(): ScriptStorage
    {
        return new Bip32ScriptStorage($this->db, $this->dbWallet, $this->gapLimit, $this->ecAdapter, $this->network);
    }

    public function getScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getExternalScriptPath());
    }

    public function getChangeScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getChangeScriptPath());
    }

    public function unlockWithAccountKey(HierarchicalKey $privAccountKey)
    {
        if (null === $this->accountPrivateKey) {
            $accountPubKey = $this->dbKey->getHierarchicalKey($this->network, $this->ecAdapter);
            if (!$privAccountKey->getPublicKey()->equals($accountPubKey->getPublicKey())) {
                throw new \RuntimeException("Private key doesn't match public key");
            }
            $this->accountPrivateKey = $privAccountKey;
        }
    }

    protected function getSigner(string $path): PrivateKeyInterface
    {
        if (null === $this->accountPrivateKey) {
            throw new \RuntimeException("private key not available");
        }
        $end = array_slice(explode("/", $path), 4);
        return $this->accountPrivateKey->deriveFromList($end)->getPrivateKey();
    }

    /**
     * @param array $txOuts
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

            $inputFee = (int) ceil(($inputWeight + 3) / 4) * $feeRate;
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
            $txOuts[] = new TransactionOutput($change, $changeScript->getScriptPubKey());
        }
        if (!shuffle($utxos)) {
            throw new \RuntimeException("utxos shuffle failed");
        }
        if (!shuffle($txOuts)) {
            throw new \RuntimeException("txouts shuffle failed");
        }
        $builder = new TxBuilder();
        foreach ($utxos as $utxo) {
            $builder->spendOutPoint($utxo->getOutPoint());
        }
        $builder->outputs($txOuts);

        return new PreparedTx($builder->get(), $utxos, $dbScripts);
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
        $unsignedTx = $prepTx->getUnsignedTx();
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
