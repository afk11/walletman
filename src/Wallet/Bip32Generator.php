<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;

class Bip32Generator implements ScriptGenerator
{
    /**
     * @var DB
     */
    private $db;

    /**
     * @var DbKey
     */
    private $dbKey;

    /**
     * @var HierarchicalKey
     */
    private $key;

    /**
     * @var int
     */
    private $gapLimit;

    public function __construct(DB $db, DbKey $dbKey, int $gapLimit, HierarchicalKey $key, NetworkInterface $network)
    {
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("cannot use leaf key with Bip32Generator");
        }
        $this->dbKey = $dbKey;
        $this->db = $db;
        $this->key = $key;
        $this->gapLimit = $gapLimit;
    }

    /**
     * @return DbScript
     * @throws \Exception
     */
    public function generate(): DbScript
    {
        $currentIndex = $this->dbKey->getNextSequence($this->db) - 1;

        for ($preDeriveIdx = $this->gapLimit + $currentIndex; $preDeriveIdx >= $currentIndex; $preDeriveIdx--) {
            $gapKeyPath = $this->dbKey->getPath() . "/$preDeriveIdx";
            if ($this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $gapKeyPath)) {
                break;
            }
            $child = $this->key->deriveChild($preDeriveIdx);
            $script = ScriptFactory::scriptPubKey()->p2pkh($child->getPublicKey()->getPubKeyHash());
            $this->db->createScript($this->dbKey->getWalletId(), $gapKeyPath, $script->getHex(), null, null);
        }

        $loadKeyPath = $this->dbKey->getPath() . "/$currentIndex";
        return $this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $loadKeyPath);
    }
}
