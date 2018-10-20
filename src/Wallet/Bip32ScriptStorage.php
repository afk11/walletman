<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Wallet\DB\DB;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;

class Bip32ScriptStorage implements ScriptStorage
{
    private $db;
    private $dbWallet;
    private $gapLimit;
    private $ecAdapter;
    private $network;

    public function __construct(DB $db, DbWallet $wallet, int $gapLimit, EcAdapterInterface $ecAdapter, NetworkInterface $network)
    {
        $this->db = $db;
        $this->dbWallet = $wallet;
        $this->gapLimit = $gapLimit;
        $this->ecAdapter = $ecAdapter;
        $this->network = $network;
    }

    public function searchScript(ScriptInterface $scriptPubKey): ?DbScript
    {
        if ($script = $this->db->loadScriptByScriptPubKey(
            $this->dbWallet->getId(),
            $scriptPubKey
        )) {
            $pathParts = explode("/", $script->getKeyIdentifier());
            $parentPath = implode("/", array_slice($pathParts, 0, -1));
            $parentKey = $this->db->loadKeyByPath($this->dbWallet->getId(), $parentPath, 0);
            $currentIndex = end($pathParts);
            $key = $parentKey->getHierarchicalKey($this->network, $this->ecAdapter);

            for ($preDeriveIdx = $this->gapLimit + $currentIndex; $preDeriveIdx >= $currentIndex; $preDeriveIdx--) {
                $gapKeyPath = $parentKey->getPath() . "/$preDeriveIdx";
                if ($this->db->loadScriptByKeyId($parentKey->getWalletId(), $gapKeyPath)) {
                    break;
                }
                $gapChild = $key->deriveChild($preDeriveIdx);
                $gapScript = ScriptFactory::scriptPubKey()->p2pkh($gapChild->getPublicKey()->getPubKeyHash());
                $this->db->createScript($parentKey->getWalletId(), $gapKeyPath, $gapScript->getHex(), null, null);
            }
            echo sprintf("Bip32: derived %d\n", ($this->gapLimit + $currentIndex) - $preDeriveIdx);
            return $script;
        }
        return null;
    }
}
