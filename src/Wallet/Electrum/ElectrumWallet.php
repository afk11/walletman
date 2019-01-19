<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet\Electrum;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\ElectrumKey;
use BitWasp\Bitcoin\Key\Factory\ElectrumKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Electrum\ElectrumWordListInterface;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\Wallet\ScriptGenerator;
use BitWasp\Wallet\Wallet\ScriptStorage;
use BitWasp\Wallet\Wallet\Wallet;

class ElectrumWallet extends Wallet
{
    const INDEX_EXTERNAL = 0;
    const INDEX_CHANGE = 1;

    private $dbKey;
    private $ecAdapter;
    /**
     * @var ElectrumKey|null
     */
    private $masterPrivateKey;

    /**
     * @var ElectrumKey
     */
    private $masterPublicKey;

    public function __construct(DBInterface $db, DbWallet $wallet, DbKey $dbKey, EcAdapterInterface $ecAdapter)
    {
        parent::__construct($db, $wallet);
        $this->dbKey = $dbKey;
        $this->ecAdapter = $ecAdapter;
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $electrumFactory = new ElectrumKeyFactory($ecAdapter);
        $this->masterPublicKey = $electrumFactory->fromKey($pubFactory->fromHex($dbKey->getBase58Key()));
    }

    public function getScriptGenerator(): ScriptGenerator
    {
        $branchNode = $this->db->loadKeyByPath($this->dbKey->getWalletId(), (string)self::INDEX_EXTERNAL, 0);
        return new \BitWasp\Wallet\Wallet\Electrum\ScriptGenerator($this->db, $branchNode, $this->dbWallet->getGapLimit(), $this->masterPublicKey);
    }

    public function getChangeScriptGenerator(): ScriptGenerator
    {
        $branchNode = $this->db->loadKeyByPath($this->dbKey->getWalletId(), (string)self::INDEX_CHANGE, 0);
        return new \BitWasp\Wallet\Wallet\Electrum\ScriptGenerator($this->db, $branchNode, $this->dbWallet->getGapLimit(), $this->masterPublicKey);
    }

    public function getScriptStorage(): ScriptStorage
    {
        return new \BitWasp\Wallet\Wallet\Electrum\ScriptStorage($this->db, $this->dbWallet, $this->dbWallet->getGapLimit(), $this->ecAdapter);
    }

    public function getSigner(string $keyIdentifier): PrivateKeyInterface
    {
        if (null === $this->masterPrivateKey) {
            throw new \RuntimeException("private key not available");
        }
        list ($change, $address) = explode(":", $keyIdentifier);
        /** @var PrivateKeyInterface $privateKey */
        $privateKey = $this->masterPrivateKey->deriveChild((int) $address, $change == self::INDEX_CHANGE);
        return $privateKey;
    }

    public function unlockWithMnemonic(string $mnemonic, ElectrumWordListInterface $wordList = null)
    {
        $factory = new ElectrumKeyFactory($this->ecAdapter);
        $masterPrivate = $factory->fromMnemonic($mnemonic, $wordList);
        if (!$masterPrivate->getMasterPublicKey()->equals($this->masterPublicKey->getMasterPublicKey())) {
            throw new \RuntimeException("Private key doesn't match master public key");
        }
        $this->masterPrivateKey = $masterPrivate;
    }

    public function isLocked(): bool
    {
        return null === $this->masterPrivateKey;
    }

    public function lockWallet()
    {
        $this->masterPrivateKey = null;
    }
}
