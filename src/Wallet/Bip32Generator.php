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

    public function __construct(DB $db, DbKey $dbKey, HierarchicalKey $key, NetworkInterface $network)
    {
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("cannot use leaf key with Bip32Generator");
        }
        $this->dbKey = $dbKey;
        $this->db = $db;
        $this->key = $key;
    }

    /**
     * @return DbScript
     * @throws \Exception
     */
    public function generate(): DbScript
    {
        $childIndex = $this->dbKey->getNextSequence($this->db);
        echo "seq $childIndex\n";
        $child = $this->key->deriveChild($childIndex);
        $path = $this->dbKey->getPath() . "/$childIndex";
        echo "just derived $path\n";
        $script = ScriptFactory::scriptPubKey()->p2pkh($child->getPublicKey()->getPubKeyHash());

        $this->db->createScript($this->dbKey->getWalletId(), $path, $script->getHex(), null, null);
        return $this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $path);
    }
}
