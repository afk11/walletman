<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Electrum;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\BlockRef;
use BitWasp\Wallet\Wallet\Electrum\ElectrumWallet;
use BitWasp\Wallet\Wallet\Factory;
use BitWasp\Wallet\Wallet\WalletType;

class FactoryTest extends DbTestCase
{
    public function testBip44WalletFromRootKey()
    {
        $identifier = "wallet-identifier";
        $mnemonic = "teach start paradise collect blade chill gay childhood creek picture creator branch";
        $gapLimit = 1;
        $birthday = null;

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));

        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createElectrumWalletFromSeed($identifier, $mnemonic, $gapLimit, $birthday, null);
        $this->assertInstanceOf(ElectrumWallet::class, $wallet);
        $this->assertEquals(WalletType::ELECTRUM_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testBip44WalletFromMasterPublicKey()
    {
        $identifier = "wallet-identifier";
        $mpk = "819519e966729f31e1855eb75133d9e7f0c31abaadd8f184870d62771c62c2e759406ace1dee933095d15e4c719617e252f32dc0465393055f867aee9357cd52";
        $gapLimit = 1;
        $birthday = null;

        $ecAdapter = Bitcoin::getEcAdapter();
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $pub = $pubFactory->fromHex("04{$mpk}");
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $wallet = $walletFactory->createElectrumWalletFromMPK($identifier, $pub, $gapLimit, $birthday);
        $this->assertInstanceOf(ElectrumWallet::class, $wallet);
        $this->assertEquals(WalletType::ELECTRUM_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNull($wallet->getDbWallet()->getBirthday());
    }

    public function testCreateWalletWithBirthday()
    {
        $identifier = "wallet-identifier";
        $mpk = "819519e966729f31e1855eb75133d9e7f0c31abaadd8f184870d62771c62c2e759406ace1dee933095d15e4c719617e252f32dc0465393055f867aee9357cd52";
        $gapLimit = 1;
        $birthday = new BlockRef(0, Buffer::hex("0f9188f13cb7b2c71f2a335e3a4fc328bf5beb436012afca590b1a11466e2206", 32));

        $ecAdapter = Bitcoin::getEcAdapter();
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $pub = $pubFactory->fromHex("04{$mpk}");
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $wallet = $walletFactory->createElectrumWalletFromMPK($identifier, $pub, $gapLimit, $birthday);
        $this->assertInstanceOf(ElectrumWallet::class, $wallet);
        $this->assertEquals(WalletType::ELECTRUM_WALLET, $wallet->getDbWallet()->getType());
        $this->assertEquals($identifier, $wallet->getDbWallet()->getIdentifier());
        $this->assertNotNull($wallet->getDbWallet()->getBirthday());
        $this->assertEquals($birthday->getHeight(), $wallet->getDbWallet()->getBirthday()->getHeight());
        $this->assertEquals($birthday->getHash()->getHex(), $wallet->getDbWallet()->getBirthday()->getHash()->getHex());
    }
}
