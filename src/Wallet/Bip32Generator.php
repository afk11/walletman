<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbScript;

class Bip32Generator implements AddressGenerator
{
    /**
     * @var HierarchicalKey
     */
    private $key;

    /**
     * @var DB
     */
    private $db;

    /**
     * @var int[]
     */
    private $path;

    /**
     * @var int
     */
    private $walletId;

    /**
     * @var int
     */
    private $idx;

    public function __construct(DB $db, int $walletId, HierarchicalKey $key, int $idx, int... $path)
    {
        $this->key = $key;
        $this->idx = $idx;
        $this->db = $db;
        $this->path = $path;
        $this->walletId = $walletId;
    }

    /**
     * @return DbScript
     * @throws \Exception
     */
    public function generate(): DbScript
    {
        $child = $this->key->deriveChild($this->idx);
        $path = array_merge($this->path, [$this->idx]);
        $this->idx++;

        $script = ScriptFactory::scriptPubKey()->p2pkh($child->getPublicKey()->getPubKeyHash());

        $sequence = new HierarchicalKeySequence();
        $keyIdentifier = $sequence->encodePath($path);
        $this->db->createScript($this->walletId, $keyIdentifier, $script->getHex(), null, null);
        $script = $this->db->loadScript($this->walletId, $keyIdentifier);
        return $script;
    }
}
