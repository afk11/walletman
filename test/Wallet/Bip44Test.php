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
use BitWasp\Wallet\Wallet\Factory;

class Bip44Test extends DbTestCase
{
    public function testBip44WalletFromAccountKey()
    {
        $identifier = "wallet-identifier";
        $absolutePath = "M/44'/0'/0'";
        $accountXpub  = "xpub6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt";
        $gapLimit = 1;

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $accountXpub);
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $absolutePath, $gapLimit, null);

        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("M/44'/0'/0'/0/0", $script->getKeyIdentifier());
        $this->assertEquals("76a9145947fbf644461dd030a795469721042a96a572aa88ac", $script->getScriptPubKey()->getHex());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("1995NdEK1EcTzDQVuVFP9yh59KU63mtXKt", $address->getAddress($this->sessionNetwork));
    }

    public function testBip49WalletFromAccountKey()
    {
        $identifier = "wallet-identifier";
        $absPath = "M/49'/0'/0'";
        $xpub = "ypub6WbvEDCFCLbUssJYTYBC9DxaKYEZZJCak5AdGJgCKeJBH5Nho9yiKBLkR6dkPpu1vBaJs5XBqFtFCuQssQUD9QV4LtqJKPePhfN9iEe1x2u";
        $gapLimit = 1;

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);
        $slip132 = new Slip132();
        $prefix = $slip132->p2shP2wpkh($registry);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [$prefix,]),
        ])));
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $xpub);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $absPath, $gapLimit, null);

        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("M/49'/0'/0'/0/0", $script->getKeyIdentifier());
        $this->assertEquals("a9147ddb5c97d4c31f4976f87dc291e9e80f0d04eba087", $script->getScriptPubKey()->getHex());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("3DAVB3H9vb5121dH3G6HTGg48tJNY7aXu9", $address->getAddress($this->sessionNetwork));
    }

    public function testBip84WalletFromAccountKey()
    {
        $identifier = "wallet-identifier";
        $absPath = "M/84'/0'/0'";
        $gapLimit = 1;
        $xpub = "zpub6rq3VG1WeoKThizrqQF58wByKWeEex6vqvwEsQ76GaMDYwHax5vMskYxBgvzgkYQ9vAEmhnFNC5YQBeRrxokRFEL7ePwsJ5ewTVEEoZ2uYP";

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();
        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);
        $slip132 = new Slip132();
        $prefix = $slip132->p2wpkh($registry);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [$prefix,]),
        ])));
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $xpub);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $absPath, $gapLimit, null);

        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("M/84'/0'/0'/0/0", $script->getKeyIdentifier());
        $this->assertEquals("0014efc22b20c7d51c1549da81b4e86baaf585a47afd", $script->getScriptPubKey()->getHex());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("bc1qalpzkgx865wp2jw6sx6ws6a27kz6g7haeyzmpc", $address->getAddress($this->sessionNetwork));
    }
}
