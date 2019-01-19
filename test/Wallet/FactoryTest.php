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
    public function testBip44WalletFromRootKey()
    {
        $identifier = "wallet-identifier";
        $xprv = "xprv9s21ZrQH143K3Q6UDriRh7GskQVErp4h9eeo2brwoURQSCJFFWnekm4s6uKxj5R187CerxjBihkSJQEAm1MeHq8U5cTESv298zCDMi2codW";
        $path = "M/44'/0'/0'";
        $gapLimit = 1;
        $birthday = null;

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromExtended($xprv, $this->sessionNetwork);
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));

        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createBip44WalletFromRootKey($identifier, $rootKey, $path, $gapLimit, $birthday);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);
        $this->assertEquals(WalletType::BIP44_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testBip44WalletFromAccountKey()
    {
        $identifier = "wallet-identifier";
        $xpub = "xpub6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt";
        $path = "M/44'/0'/0'";
        $gapLimit = 1;
        $birthday = null;

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $xpub);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $gapLimit, $birthday);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);
        $this->assertEquals(WalletType::BIP44_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testCreateWalletWithBirthday()
    {
        $identifier = "wallet-identifier";
        $xpub = "xpub6DSWue4h7KmDp42bFGLqVx2HRRZnSrnHakZNKjYjiXnKehREdPfUocvmKT1XXsSenQvLRxjj4L7vCWXnPJY7QoUzXYvz2D5Z7pTEWR7sAqt";
        $path = "M/44'/0'/0'";
        $gapLimit = 1;
        $birthday = new BlockRef(0, Buffer::hex("0f9188f13cb7b2c71f2a335e3a4fc328bf5beb436012afca590b1a11466e2206", 32));

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $accountKey = $hdSerializer->parse($this->sessionNetwork, $xpub);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createBip44WalletFromAccountKey($identifier, $accountKey, $path, $gapLimit, $birthday);
        $this->assertInstanceOf(Bip44Wallet::class, $wallet);

        /** @var BlockRef $dbBirthday */
        $dbBirthday = $wallet->getDbWallet()->getBirthday();
        $this->assertInstanceOf(BlockRef::class, $dbBirthday);
        $this->assertEquals($birthday->getHeight(), $dbBirthday->getHeight());
        $this->assertEquals($birthday->getHash()->getHex(), $dbBirthday->getHash()->getHex());
    }
}
