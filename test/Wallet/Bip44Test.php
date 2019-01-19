<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\HdWallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;

class Bip44Test extends DbTestCase
{
    public function getBip4XTestFixtures(): array
    {
        return [
            ["M/44'/0'/0'", "xpub6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt", "76a9145947fbf644461dd030a795469721042a96a572aa88ac", "1995NdEK1EcTzDQVuVFP9yh59KU63mtXKt", "76a9143803b3f6910d1155a0907d072c2420e872c60c0688ac", "167BH2kPH27wrPhmTVuW13VEbPpXsYynxP"],
            ["M/49'/0'/0'", "ypub6WbvEDCFCLbUssJYTYBC9DxaKYEZZJCak5AdGJgCKeJBH5Nho9yiKBLkR6dkPpu1vBaJs5XBqFtFCuQssQUD9QV4LtqJKPePhfN9iEe1x2u", "a9147ddb5c97d4c31f4976f87dc291e9e80f0d04eba087", "3DAVB3H9vb5121dH3G6HTGg48tJNY7aXu9", "a9140f517cd0eb797956db5bca671ee09e9f70924b3487", "3361gGyrHvjCTij49UTqEXbmEbvswaCiEu"],
            ["M/84'/0'/0'", "zpub6rq3VG1WeoKThizrqQF58wByKWeEex6vqvwEsQ76GaMDYwHax5vMskYxBgvzgkYQ9vAEmhnFNC5YQBeRrxokRFEL7ePwsJ5ewTVEEoZ2uYP", "0014efc22b20c7d51c1549da81b4e86baaf585a47afd", "bc1qalpzkgx865wp2jw6sx6ws6a27kz6g7haeyzmpc", "0014274093bcfd40cb31b72ad2c8813812a93bc0f6b3", "bc1qyaqf808agr9nrde26tygzwqj4yaupa4nu48c6m"],
        ];
    }

    /**
     * @param string $accountPath
     * @param string $accountXpub
     * @param string $extScript1
     * @param string $extAddr1
     * @param string $intScript1
     * @param string $intAddr1
     * @dataProvider getBip4XTestFixtures
     */
    public function testFirstAddressFromFirstKey(string $accountPath, string $accountXpub, string $extScript1, string $extAddr1, string $intScript1, string $intAddr1)
    {
        $identifier = "wallet-identifier";
        $gapLimit = 1;

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);

        // init with all prefixes we support
        $slip132 = new Slip132();
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $accountXpub);
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $accountPath, $gapLimit, null);
        $this->assertEquals($accountPath, $wallet->getAccountPath());
        $this->assertEquals("$accountPath/" . Bip44Wallet::INDEX_EXTERNAL, $wallet->getExternalScriptPath());
        $this->assertEquals("$accountPath/" . Bip44Wallet::INDEX_CHANGE, $wallet->getChangeScriptPath());

        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("$accountPath/".Bip44Wallet::INDEX_EXTERNAL."/0", $script->getKeyIdentifier());
        $this->assertEquals($extScript1, $script->getScriptPubKey()->getHex());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("$extAddr1", $address->getAddress($this->sessionNetwork));

        $script = $wallet->getChangeScriptGenerator()->generate();
        $this->assertEquals("$accountPath/".Bip44Wallet::INDEX_CHANGE."/0", $script->getKeyIdentifier());
        $this->assertEquals($intScript1, $script->getScriptPubKey()->getHex());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals($intAddr1, $address->getAddress($this->sessionNetwork));
    }
}
