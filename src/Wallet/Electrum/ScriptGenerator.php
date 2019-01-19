<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet\Electrum;

use BitWasp\Bitcoin\Key\Deterministic\ElectrumKey;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;

class ScriptGenerator implements \BitWasp\Wallet\Wallet\ScriptGenerator
{
    /**
     * @var DBInterface
     */
    private $db;

    /**
     * @var DbKey
     */
    private $dbKey;

    /**
     * @var ElectrumKey
     */
    private $key;

    /**
     * @var int
     */
    private $gapLimit;

    public function __construct(DBInterface $db, DbKey $dbKey, int $gapLimit, ElectrumKey $key)
    {
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

        $spkFactory = ScriptFactory::scriptPubKey();
        for ($preDeriveIdx = $this->gapLimit + $currentIndex; $preDeriveIdx >= $currentIndex; $preDeriveIdx--) {
            $gapKeyPath = $this->dbKey->getPath() . ":$preDeriveIdx";
            if ($this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $gapKeyPath)) {
                break;
            }

            $gapChild = $this->key->deriveChild($preDeriveIdx);
            $script = $spkFactory->p2pkh($gapChild->getPubKeyHash());

            $rs = "";
            $ws = "";
            $this->db->createScript($this->dbKey->getWalletId(), $gapKeyPath, $script->getHex(), $rs, $ws);
        }

        $loadKeyPath = $this->dbKey->getPath() . ":$currentIndex";
        /** @var DbScript $script */
        $script = $this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $loadKeyPath);
        return $script;
    }
}
