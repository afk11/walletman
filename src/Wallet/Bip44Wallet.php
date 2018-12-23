<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Wallet;

use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Network\NetworkInterface;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbKey;
use BitWasp\Wallet\DB\DbWallet;

class Bip44Wallet extends HdWallet
{
    const INDEX_EXTERNAL = 0;
    const INDEX_CHANGE = 1;

    public function __construct(DBInterface $db, Base58ExtendedKeySerializer $serializer, DbWallet $wallet, DbKey $dbKey, NetworkInterface $network, EcAdapterInterface $ecAdapter)
    {
        if ($dbKey->getDepth() !== 3) {
            throw new \RuntimeException("invalid key depth for bip44 account, should provide M/purpose'/coinType'/account'");
        }
        if ($dbKey->isLeaf()) {
            throw new \RuntimeException("invalid key for bip44 account, should be a branch node");
        }

        parent::__construct($db, $serializer, $wallet, $dbKey, $network, $ecAdapter);
    }

    protected function getExternalScriptPath(): string
    {
        return $this->dbKey->getPath() . "/" . self::INDEX_EXTERNAL;
    }

    protected function getChangeScriptPath(): string
    {
        return $this->dbKey->getPath() . "/" . self::INDEX_CHANGE;
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
