<?php
declare(strict_types=1);
namespace BitWasp\Wallet\DB;

use BitWasp\Bitcoin\Address\AddressInterface;
use BitWasp\Bitcoin\Address\BaseAddressCreator;
use BitWasp\Bitcoin\Script\P2shScript;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Bitcoin\Transaction\Factory\SignData;

class DbScript
{
    private $id;
    private $walletId;
    private $scriptPubKey;
    private $redeemScript;
    private $witnessScript;
    private $keyIdentifier;

    public function getId(): int {
        return (int) $this->id;
    }

    public function getKeyIdentifier(): string {
        return $this->keyIdentifier;
    }

    public function getSignData(): SignData
    {
        $signData = new SignData();
        if ($this->redeemScript) {
            $signData->p2sh(new P2shScript(ScriptFactory::fromHex($this->redeemScript)));
        }
        if ($this->witnessScript) {
            $signData->p2wsh(new WitnessScript(ScriptFactory::fromHex($this->redeemScript)));
        }
        return $signData;
    }
    public function getScriptPubKey(): ScriptInterface {
        return ScriptFactory::fromHex($this->scriptPubKey);
    }

    public function getAddress(BaseAddressCreator $addressCreator): AddressInterface
    {
        return $addressCreator->fromOutputScript(ScriptFactory::fromHex($this->scriptPubKey));
    }
}
