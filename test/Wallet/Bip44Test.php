<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\Factory;

class Bip44Test extends DbTestCase
{
    public function testBip44WalletFromAccountKey()
    {
        $mnemonic = "deer position make range avocado hold soldier view luggage motor sweet account";
        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromEntropy($seed);
        $path = "44'/0'/0'";
        $accountKey = $rootKey->derivePath($path)->withoutPrivateKey();

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 5;
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("76a9145947fbf644461dd030a795469721042a96a572aa88ac", $script->getScriptPubKey()->getHex());

        $addrCreator = new AddressCreator();
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("1995NdEK1EcTzDQVuVFP9yh59KU63mtXKt", $address->getAddress($this->sessionNetwork));
    }

    public function testBip49WalletFromAccountKey()
    {
        $mnemonic = "deer position make range avocado hold soldier view luggage motor sweet account";
        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";

        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);
        $slip132 = new Slip132();
        $prefix = $slip132->p2shP2wpkh($registry);
        $conf = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [$prefix,]),
        ]);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $conf));
        $hdFactory = new HierarchicalKeyFactory($ecAdapter, $hdSerializer);
        $rootKey = $hdFactory->fromEntropy($seed, $prefix->getScriptDataFactory());
        $path = "49'/0'/0'";
        $accountKey = $rootKey->derivePath($path)->withoutPrivateKey();

        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 5;
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("a9147ddb5c97d4c31f4976f87dc291e9e80f0d04eba087", $script->getScriptPubKey()->getHex());

        $addrCreator = new AddressCreator();
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("3DAVB3H9vb5121dH3G6HTGg48tJNY7aXu9", $address->getAddress($this->sessionNetwork));
    }

    public function testBip84WalletFromAccountKey()
    {
        $mnemonic = "deer position make range avocado hold soldier view luggage motor sweet account";
        $seed = (new Bip39SeedGenerator())->getSeed($mnemonic);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";

        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);
        $slip132 = new Slip132();
        $prefix = $slip132->p2wpkh($registry);
        $conf = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [$prefix,]),
        ]);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $conf));
        $hdFactory = new HierarchicalKeyFactory($ecAdapter, $hdSerializer);
        $rootKey = $hdFactory->fromEntropy($seed, $prefix->getScriptDataFactory());
        $path = "84'/0'/0'";
        $accountKey = $rootKey->derivePath($path)->withoutPrivateKey();

        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 5;
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals("0014efc22b20c7d51c1549da81b4e86baaf585a47afd", $script->getScriptPubKey()->getHex());

        $addrCreator = new AddressCreator();
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("bc1qalpzkgx865wp2jw6sx6ws6a27kz6g7haeyzmpc", $address->getAddress($this->sessionNetwork));
    }
}
