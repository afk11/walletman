<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKey;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbScript;

class Bip32Generator implements ScriptGenerator
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
     * @var HierarchicalKey
     */
    private $key;

    /**
     * @var int
     */
    private $gapLimit;

    public function __construct(DBInterface $db, DbKey $dbKey, int $gapLimit, HierarchicalKey $key)
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
            $scriptAndSignData = $child->getScriptAndSignData();

            $rs = "";
            $ws = "";
            if ($scriptAndSignData->getSignData()->hasRedeemScript()) {
                $rs = $scriptAndSignData->getSignData()->getRedeemScript()->getHex();
            }
            if ($scriptAndSignData->getSignData()->hasWitnessScript()) {
                $ws = $scriptAndSignData->getSignData()->getWitnessScript()->getHex();
            }
            $this->db->createScript($this->dbKey->getWalletId(), $gapKeyPath, $scriptAndSignData->getScriptPubKey()->getHex(), $rs, $ws);
        }

        $loadKeyPath = $this->dbKey->getPath() . "/$currentIndex";
        /** @var DbScript $script */
        $script = $this->db->loadScriptByKeyId($this->dbKey->getWalletId(), $loadKeyPath);
        return $script;
    }
}
