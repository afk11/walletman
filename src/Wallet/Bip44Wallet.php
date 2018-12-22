<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Key\PrivateKeyInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;
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

    public function getScriptByPath(string $path): ?DbScript
    {
        return $this->db->loadScriptByKeyIdentifier($this->dbKey->getWalletId(), $path);
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

    public function isLocked(): bool
    {
        return null === $this->accountPrivateKey;
    }

    public function lockWallet()
    {
        $this->accountPrivateKey = null;
    }

    protected function getSigner(string $path): PrivateKeyInterface
    {
        if (null === $this->accountPrivateKey) {
            throw new \RuntimeException("private key not available");
        }
        return $this->accountPrivateKey->derivePath(implode("/", array_slice(explode("/", $path), 4)))->getPrivateKey();
    }
}
