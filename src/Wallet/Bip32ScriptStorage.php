<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
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
    private $serializer;

    public function __construct(DB $db, Base58ExtendedKeySerializer $serializer, DbWallet $wallet, int $gapLimit, EcAdapterInterface $ecAdapter, NetworkInterface $network)
    {
        $this->db = $db;
        $this->dbWallet = $wallet;
        $this->gapLimit = $gapLimit;
        $this->ecAdapter = $ecAdapter;
        $this->network = $network;
        $this->serializer = $serializer;
    }

    public function searchScript(ScriptInterface $scriptPubKey): ?DbScript
    {
        if (!$script = $this->db->loadScriptByScriptPubKey(
            $this->dbWallet->getId(),
            $scriptPubKey
        )) {
            return null;
        }

        $pathParts = explode("/", $script->getKeyIdentifier());
        $parentPath = implode("/", array_slice($pathParts, 0, -1));
        $parentKey = $this->db->loadKeyByPath($this->dbWallet->getId(), $parentPath, 0);
        $currentIndex = end($pathParts);
        $key = $this->serializer->parse($this->network, $parentKey->getBase58Key());

        for ($preDeriveIdx = $this->gapLimit + $currentIndex; $preDeriveIdx >= $currentIndex; $preDeriveIdx--) {
            $gapKeyPath = $parentKey->getPath() . "/$preDeriveIdx";
            if ($this->db->loadScriptByKeyId($parentKey->getWalletId(), $gapKeyPath)) {
                break;
            }
            $gapChild = $key->deriveChild($preDeriveIdx);
            $scriptAndSignData = $gapChild->getScriptAndSignData();

            $rs = "";
            $ws = "";
            if ($scriptAndSignData->getSignData()->hasRedeemScript()) {
                $rs = $scriptAndSignData->getSignData()->getRedeemScript()->getHex();
            }
            if ($scriptAndSignData->getSignData()->hasWitnessScript()) {
                $ws = $scriptAndSignData->getSignData()->getWitnessScript()->getHex();
            }
            $this->db->createScript($parentKey->getWalletId(), $gapKeyPath, $scriptAndSignData->getScriptPubKey()->getHex(), $rs, $ws);
        }

        return $script;
    }
}
