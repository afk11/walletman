<?php

namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Address\AddressFactory;
use BitWasp\Bitcoin\Address\AddressInterface;
use BitWasp\Bitcoin\Script\ScriptFactory;

class DbScript
{
    private $id;
    private $walletId;
    private $scriptPubKey;
    private $redeemScript;
    private $witnessScript;

    public function getAddress(): AddressInterface
    {
        return AddressFactory::fromOutputScript(ScriptFactory::fromHex($this->scriptPubKey));
    }
}
