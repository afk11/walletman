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
use BitWasp\Wallet\DB\DbWallet;

class Bip44Wallet implements WalletInterface
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
     * @var DB
     */
    private $db;

    /**
     * @var EcAdapterInterface
     */
    private $ecAdapter;

    /**
     * @var DbWallet
     */
    private $dbWallet;

    private $gapLimit;

    /**
     * @var HierarchicalKey
     */
    private $accountPrivateKey;

    public function __construct(DB $db, DbWallet $wallet, DbKey $dbKey, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        if ($dbKey->getDepth() !== 3) {
            throw new \RuntimeException("invalid key depth for bip44 account, should provide M/purpose'/coinType'/account'");
        }
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("invalid key for bip44 account, should be a branch node");
        }

        $this->gapLimit = 100;
        $this->db = $db;
        $this->dbKey = $dbKey;
        $this->dbWallet = $wallet;
        $this->network = $network;
        $this->ecAdapter = $ecAdapter;
    }

    public function getDbWallet(): DbWallet
    {
        return $this->dbWallet;
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
        return new Bip32Generator($this->db, $branchNode, $this->gapLimit, $key, $this->network);
    }

    public function getScriptStorage(): ScriptStorage
    {
        return new Bip32ScriptStorage($this->db, $this->dbWallet, $this->gapLimit, $this->ecAdapter, $this->network);
    }

    public function getScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getExternalScriptPath());
    }
    public function getUtxoStorage(): UtxoStorage
    {
        return new UtxoStorage($this->db, $this->dbWallet);
    }
    public function getChangeScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getChangeScriptPath());
    }

    public function loadAccountPrivateKey(HierarchicalKey $privAccountKey) {
        if (null === $this->accountPrivateKey) {
            $accountPubKey = $this->dbKey->getHierarchicalKey($this->network, $this->ecAdapter);
            if (!$privAccountKey->getPublicKey()->equals($accountPubKey->getPublicKey())) {
                throw new \RuntimeException("Private key doesn't match public key");
            }
            $this->accountPrivateKey = $privAccountKey;
        }
    }

    private function getPrivateKey(string $path): PrivateKeyInterface
    {
        if (!$this->accountPrivateKey) {
            throw new \RuntimeException("private key not available");
        }
        $end = array_slice(explode("/", $path), 4);
        print_r($end);
        return $this->accountPrivateKey->deriveFromList($end)->getPrivateKey();
    }

    public function sendAllCoins(ScriptInterface $destination, int $feeRate): TransactionInterface
    {
        $utxos = $this->db->getWalletUtxos($this->dbWallet->getId());
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
        echo "estimate size: $estimatedVsize\n";
        $fee = $estimatedVsize * $feeRate;
        if ($fee > $valueIn) {
            throw new \RuntimeException("Insufficient funds for fee");
        }
        $txBuilder->output($valueIn - $fee, $destination);
        $unsignedTx = $txBuilder->get();
        $signer = new Signer($unsignedTx);
        foreach ($inputScripts as $i => $scriptAndSignData) {
            $priv = $this->getPrivateKey($dbScripts[$i]->getKeyIdentifier());
            $signer->input($i, $utxos[$i]->getTxOut())
                ->sign($priv);
        }
        $signedTx = $signer->get();
        return $signedTx;
    }
}
