<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;

class Bip44Wallet implements WalletInterface
{
    const INDEX_EXTERNAL = 0;
    const INDEX_CHANGE = 1;

    /**
     * @var HierarchicalKey
     */
    private $key;

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

    public function __construct(DB $db, DbKey $dbKey, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        if ($dbKey->getDepth() !== 3) {
            throw new \RuntimeException("invalid key depth for bip44 account, should provide M/purpose'/coinType'/account'");
        }
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("invalid key for bip44 account, should be a branch node");
        }

        $this->db = $db;
        $this->dbKey = $dbKey;
        $this->network = $network;
        $this->key = $dbKey->getHierarchicalKey($network, $ecAdapter);
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
        return new Bip32Generator($this->db, $branchNode, $key, $this->network);
    }

    public function getScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getExternalScriptPath());
    }

    public function getChangeScriptGenerator(): ScriptGenerator
    {
        return $this->getGeneratorForPath($this->getChangeScriptPath());
    }
}
