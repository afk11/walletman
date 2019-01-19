<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet\Electrum;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcSerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Key\PublicKeySerializerInterface;
use BitWasp\Bitcoin\Key\Deterministic\ElectrumKey;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\DB\DbWallet;

class ScriptStorage implements \BitWasp\Wallet\Wallet\ScriptStorage
{
    private $db;
    private $ecAdapter;
    private $dbWallet;
    private $gapLimit;

    public function __construct(DBInterface $db, DbWallet $wallet, int $gapLimit, EcAdapterInterface $ecAdapter)
    {
        $this->db = $db;
        $this->dbWallet = $wallet;
        $this->gapLimit = $gapLimit;
        $this->ecAdapter = $ecAdapter;
    }

    public function searchScript(ScriptInterface $scriptPubKey): ?DbScript
    {
        if (!$script = $this->db->loadScriptByScriptPubKey(
            $this->dbWallet->getId(),
            $scriptPubKey
        )) {
            return null;
        }

        $change = substr($script->getKeyIdentifier(), 0, 1) === "1";
        $parentKey = $this->db->loadKeyByPath($this->dbWallet->getId(), $change ? ElectrumWallet::INDEX_CHANGE : ElectrumWallet::class, 0);
        $currentIndex = $parentKey->getChildSequence();
        /** @var PublicKeySerializerInterface $pubKeySer */
        $pubKeySer = EcSerializer::getSerializer(PublicKeySerializerInterface::class, true, $this->ecAdapter);
        $key = new ElectrumKey($pubKeySer->parse(Buffer::hex($parentKey->getBase58Key())));
        $spkFactory = ScriptFactory::scriptPubKey();

        for ($preDeriveIdx = $this->gapLimit + $currentIndex; $preDeriveIdx >= $currentIndex; $preDeriveIdx--) {
            $gapKeyIdentifier = $parentKey->getPath() . ":$preDeriveIdx";
            if ($this->db->loadScriptByKeyId($parentKey->getWalletId(), $gapKeyIdentifier)) {
                break;
            }
            $gapChild = $key->deriveChild($preDeriveIdx);
            $script = $spkFactory->p2pkh($gapChild->getPubKeyHash());

            $rs = "";
            $ws = "";
            $this->db->createScript($this->dbWallet->getWalletId(), $gapKeyIdentifier, $script->getHex(), $rs, $ws);
        }

        return $script;
    }
}
