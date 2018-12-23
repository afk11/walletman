<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;
use BitWasp\Wallet\Wallet\WalletType;

class FactoryTest extends DbTestCase
{
    protected $regtest = true;

    public function testBip44WalletFromRootKey()
    {
        $random = new Buffer("", 32);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromEntropy($random);
        $path = "M/44'/0'/0'";

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, $path, null);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);
        $this->assertEquals(WalletType::BIP44_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testBip44WalletFromAccountKey()
    {
        $random = new Buffer("", 32);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromEntropy($random);
        $path = "44'/0'/0'";
        $accountKey = $rootKey->derivePath($path)->withoutPrivateKey();

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, null);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);
        $this->assertEquals(WalletType::BIP44_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testCreateWalletWithBirthday()
    {
        $random = new Buffer("", 32);
        $ecAdapter = Bitcoin::getEcAdapter();
        $identifier = "wallet-identifier";
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromEntropy($random);
        $path = "M/44'/0'/0'";
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $birthday = new BlockRef(0, Buffer::hex("0f9188f13cb7b2c71f2a335e3a4fc328bf5beb436012afca590b1a11466e2206", 32));
        $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, $path, $birthday);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);
        $this->assertNotNull($wallet->getDbWallet()->getBirthday());
        $this->assertEquals($birthday->getHeight(), $wallet->getDbWallet()->getBirthday()->getHeight());
        $this->assertEquals($birthday->getHash()->getHex(), $wallet->getDbWallet()->getBirthday()->getHash()->getHex());
    }
}
