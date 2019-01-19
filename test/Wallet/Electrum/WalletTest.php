<?php declare(strict_types=1);

namespace BitWasp\Test\Wallet\Electrum;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\ElectrumKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Script\Interpreter\Interpreter;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\Wallet\Electrum\ElectrumWallet;
use BitWasp\Wallet\Wallet\Factory;
use BitWasp\Wallet\Wallet\SizeEstimation;

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
     * @throws \Exception
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

    public function testSpendAll()
    {
        $mnemonic = "teach start paradise collect blade chill gay childhood creek picture creator branch";

        $ecAdapter = Bitcoin::getEcAdapter();
        $electrumFactory = new ElectrumKeyFactory($ecAdapter);

        // generate a random pubkeyhash address to sent our balance to
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $destPub = $pubFactory->fromHex("029c730c91292e556c50d6fcfe6a7601435317c7cb2cd1399de5f350208e2691fb");
        $destAddr = new PayToPubKeyHashAddress($destPub->getPubKeyHash());

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));

        $rootKey = $electrumFactory->fromMnemonic($mnemonic);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 2;
        $wallet = $walletFactory->createElectrumWalletFromMPK("wallet-identifier", $rootKey->getMasterPublicKey(), $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $spk = $script->getScriptPubKey();
        $txid = new Buffer("\x42", 32);

        $feeRate = 5;
        $inputCoinAmounts = [100000000, 200000000, 10000000];
        $shouldSpend = [];
        $totalIn = 0;
        foreach ($inputCoinAmounts as $i => $amount) {
            $outPoint = new OutPoint($txid, $i);
            $shouldSpend[] = $outPoint;
            $totalIn += $amount;
            $this->sessionDb->createUtxo($wallet->getDbWallet(), $script, $outPoint, new TransactionOutput($amount, $spk));
        }

        $prepared = $wallet->sendAllCoins($destAddr->getScriptPubKey(), $feeRate);
        $this->assertCount(count($inputCoinAmounts), $prepared->getTx()->getInputs());
        $this->assertCount(1, $prepared->getTx()->getOutputs());
        $this->assertEquals($destAddr->getScriptPubKey()->getHex(), $prepared->getTx()->getOutput(0)->getScript()->getHex());
        $tx = $prepared->getTx();
        $totalOut = $tx->getOutput(0)->getValue();

        $inputAmounts = [];
        foreach ($shouldSpend as $idx => $outpoint) {
            $found = false;
            foreach ($tx->getInputs() as $inIdx => $input) {
                if ($input->getOutPoint()->equals($outpoint)) {
                    $found = true;
                    $inputAmounts[$inIdx] = $inputCoinAmounts[$idx];
                    break;
                }
            }
            $this->assertTrue($found, "expected input to be included in transaction");
        }

        $scriptAndSignData = new ScriptAndSignData($spk, $script->getSignData());
        $estimatedVsize = SizeEstimation::estimateVsize([$scriptAndSignData, $scriptAndSignData, $scriptAndSignData,], [new TransactionOutput(0, $destAddr->getScriptPubKey())]);
        $this->assertEquals($totalIn - $estimatedVsize * $feeRate, $totalOut);

        /** @var ElectrumWallet $wallet */
        $this->assertTrue($wallet->isLocked());
        $wallet->unlockWithMnemonic($mnemonic);
        $this->assertFalse($wallet->isLocked());

        $signed = $wallet->signTx($prepared);
        $consensus = ScriptFactory::consensus($ecAdapter);
        $flags = Interpreter::VERIFY_P2SH | Interpreter::VERIFY_WITNESS;
        foreach ($signed->getInputs() as $i => $input) {
            $this->assertTrue($consensus->verify($signed, $spk, $flags, $i, $inputAmounts[$i]));
        }
    }
}
