<?php declare(strict_types=1);

namespace BitWasp\Test\Wallet\Electrum;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\ElectrumKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\Wallet\Electrum\ElectrumWallet;
use BitWasp\Wallet\Wallet\Factory;

class WalletTest extends DbTestCase
{

    public function getFixtures(): array
    {
        return [
            ["819519e966729f31e1855eb75133d9e7f0c31abaadd8f184870d62771c62c2e759406ace1dee933095d15e4c719617e252f32dc0465393055f867aee9357cd52", "15ZL6i899dDBXm8NoXwn7oup4J5yQJi1NH",],
        ];
    }

    /**
     * @param string $mpk
     * @param string $extAddr1
     * @dataProvider getFixtures
     */
    public function testFirstAddressFromFirstKey(string $mpk, string $extAddr1)
    {
        $identifier = "wallet-identifier";
        $gapLimit = 1;

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();

        // init with all prefixes we support
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $pub = $pubFactory->fromHex("04{$mpk}");
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createElectrumWalletFromMPK($identifier, $pub, $gapLimit, null);

        $script = $wallet->getScriptGenerator()->generate();
        $this->assertEquals(ElectrumWallet::INDEX_EXTERNAL.":0", $script->getKeyIdentifier());
        $address = $addrCreator->fromOutputScript($script->getScriptPubKey());
        $this->assertEquals("$extAddr1", $address->getAddress($this->sessionNetwork));
    }

    public function testUnlock()
    {
        $mnemonic = "teach start paradise collect blade chill gay childhood creek picture creator branch";
        $mpk = "819519e966729f31e1855eb75133d9e7f0c31abaadd8f184870d62771c62c2e759406ace1dee933095d15e4c719617e252f32dc0465393055f867aee9357cd52";

        $identifier = "wallet-identifier";
        $gapLimit = 1;

        $ecAdapter = Bitcoin::getEcAdapter();
        $addrCreator = new AddressCreator();

        // init with all prefixes we support
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $pub = $pubFactory->fromHex("04{$mpk}");

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);
        $wallet = $walletFactory->createElectrumWalletFromMPK($identifier, $pub, $gapLimit, null);
        /** @var ElectrumWallet $wallet */
        $this->assertTrue($wallet->isLocked());
        $wallet->unlockWithMnemonic($mnemonic);
        $this->assertFalse($wallet->isLocked());
        $wallet->lockWallet();
        $this->assertTrue($wallet->isLocked());
    }
}
