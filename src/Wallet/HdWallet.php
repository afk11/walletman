<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;

abstract class HdWallet extends Wallet
{
    const INDEX_EXTERNAL = 0;
    const INDEX_CHANGE = 1;

    /**
     * @var DbKey
     */
    protected $dbKey;

    /**
     * @var NetworkInterface
     */
    protected $network;

    /**
     * @var EcAdapterInterface
     */
    protected $ecAdapter;

    /**
     * @var int
     */
    protected $gapLimit;

    /**
     * @var HierarchicalKey
     */
    protected $accountPrivateKey;

    /**
     * @var Base58ExtendedKeySerializer
     */
    protected $serializer;

    public function __construct(DB $db, Base58ExtendedKeySerializer $serializer, DbWallet $wallet, DbKey $dbKey, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        parent::__construct($db, $wallet);

        $this->gapLimit = 100;
        $this->dbKey = $dbKey;
        $this->network = $network;
        $this->ecAdapter = $ecAdapter;
        $this->serializer = $serializer;
    }

    protected function getGeneratorForPath(string $path): ScriptGenerator
    {
        $branchNode = $this->db->loadKeyByPath($this->dbKey->getWalletId(), $path, 0);
        $key = $this->serializer->parse($this->network, $branchNode->getBase58Key());
        return new Bip32Generator($this->db, $branchNode, $this->gapLimit, $key);
    }

    public function getScriptStorage(): ScriptStorage
    {
        return new Bip32ScriptStorage($this->db, $this->serializer, $this->dbWallet, $this->gapLimit, $this->ecAdapter, $this->network);
    }

    public function getScriptByPath(string $path): ?DbScript
    {
        return $this->db->loadScriptByKeyIdentifier($this->dbKey->getWalletId(), $path);
    }

    public function getSigner(string $path): PrivateKeyInterface
    {
        if (null === $this->accountPrivateKey) {
            throw new \RuntimeException("private key not available");
        }
        return $this->accountPrivateKey->derivePath(implode("/", array_slice(explode("/", $path), 4)))->getPrivateKey();
    }

    public function unlockWithAccountKey(HierarchicalKey $privAccountKey)
    {
        if (null === $this->accountPrivateKey) {
            $accountPubKey = $this->serializer->parse($this->network, $this->dbKey->getBase58Key());
            if (!$privAccountKey->getPublicKey()->equals($accountPubKey->getPublicKey())) {
                throw new \RuntimeException("Private key doesn't match public key");
            }
            $this->accountPrivateKey = $privAccountKey;
        }
    }

    public function isLocked(): bool
    {
        return null === $this->accountPrivateKey;
    }

    public function lockWallet()
    {
        $this->accountPrivateKey = null;
    }
}
