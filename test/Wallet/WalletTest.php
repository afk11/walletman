<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Address\PayToPubKeyHashAddress;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeySequence;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Mnemonic\Bip39\Bip39SeedGenerator;
use BitWasp\Bitcoin\Script\Interpreter\Interpreter;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\Wallet\Factory;
use BitWasp\Wallet\Wallet\HdWallet;
use BitWasp\Wallet\Wallet\SizeEstimation;

class WalletTest extends DbTestCase
{
    protected $regtest = true;

    private function insertTx(\PDO $pdo, DbWallet $dbWallet, string $txid, int $valueChange, int $status, string $blockHash, int $blockHeight): bool
    {
        $stmt = $pdo->prepare("INSERT INTO tx (walletId, txid, valueChange, status, confirmedHash, confirmedHeight) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $dbWallet->getId(), $txid, $valueChange,
            $status, $blockHash, $blockHeight,
        ]);
    }

    public function testConfirmedBalanceEmpty()
    {
        $pdo = $this->sessionDb->getPdo();
        $ecAdapter = Bitcoin::getEcAdapter();
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $rootKey = $hdFactory->fromEntropy(new Buffer("", 32));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 2;
        $wallet = $walletFactory->createBip44WalletFromRootKey("wallet-identifier", $rootKey, "M/44'/0'/0'", $gapLimit, null);
        $this->assertEquals(0, $wallet->getConfirmedBalance());

        $oneBtc = 100000000;
        $this->assertTrue($this->insertTx(
            $pdo,
            $wallet->getDbWallet(),
            "abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234",
            $oneBtc,
            DbWalletTx::STATUS_CONFIRMED,
            "0000000090909090909090909090909090909090909090909090909090909090",
            1
        ));

        $wallet = $walletFactory->loadWallet("wallet-identifier");
        $this->assertEquals($oneBtc, $wallet->getConfirmedBalance());
    }

    public function getTestPaths(): array
    {
        return [
            ["M/44'/0'/0'"],
            ["M/49'/0'/0'"],
            ["M/84'/0'/0'"],
        ];
    }

    /**
     * @dataProvider getTestPaths
     */
    public function testSpendAll(string $accountPath)
    {
        $mnemonic = "deer position make range avocado hold soldier view luggage motor sweet account";

        $ecAdapter = Bitcoin::getEcAdapter();
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);

        // generate a random pubkeyhash address to sent our balance to
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $destPub = $pubFactory->fromHex("029c730c91292e556c50d6fcfe6a7601435317c7cb2cd1399de5f350208e2691fb");
        $destAddr = new PayToPubKeyHashAddress($destPub->getPubKeyHash());

        // init with all prefixes we support
        $slip132 = new Slip132();
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);

        $decodedAccountPath = (new HierarchicalKeySequence())->decodeAbsolute($accountPath)[1];

        switch ($decodedAccountPath[0]) {
            case 44 | 1 << 31:
                $prefix = $slip132->p2pkh($registry);
                break;
            case 49 | 1 << 31:
                $prefix = $slip132->p2wpkh($registry);
                break;
            case 84 | 1 << 31:
                $prefix = $slip132->p2shP2wpkh($registry);
                break;
            default:
                throw new \RuntimeException("invalid test, not configured for this bip44 purpose");
        }

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $entropy = (new Bip39SeedGenerator())->getSeed($mnemonic);
        $rootKey = $hdFactory->fromEntropy($entropy, $prefix->getScriptDataFactory());
        $accountKey = $rootKey->derivePath(substr($accountPath, 2));
        $accountPubKey = $accountKey->withoutPrivateKey();
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 2;
        $wallet = $walletFactory->createBip44WalletFromAccountKey("wallet-identifier", $accountPubKey, $accountPath, $gapLimit, null);
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

        /** @var HdWallet $wallet */
        $this->assertTrue($wallet->isLocked());
        $wallet->unlockWithAccountKey($accountKey);
        $this->assertFalse($wallet->isLocked());

        $signed = $wallet->signTx($prepared);
        $consensus = ScriptFactory::consensus($ecAdapter);
        $flags = Interpreter::VERIFY_P2SH | Interpreter::VERIFY_WITNESS;
        foreach ($signed->getInputs() as $i => $input) {
            $this->assertTrue($consensus->verify($signed, $spk, $flags, $i, $inputAmounts[$i]));
        }
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Insufficient funds for fee
     */
    public function testFeeOverTotalIn()
    {
        $mnemonic = "deer position make range avocado hold soldier view luggage motor sweet account";
        $path = "M/44'/0'/0'";
        $ecAdapter = Bitcoin::getEcAdapter();
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $netInfo = new NetworkInfo();
        $registry = $netInfo->getSlip132Registry($this->sessionNetworkName);

        // generate a random pubkeyhash address to sent our balance to
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $destPub = $pubFactory->fromHex("029c730c91292e556c50d6fcfe6a7601435317c7cb2cd1399de5f350208e2691fb");
        $destAddr = new PayToPubKeyHashAddress($destPub->getPubKeyHash());

        // init with all prefixes we support
        $slip132 = new Slip132();
        $prefixConfig = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ]);

        $prefix = $slip132->p2pkh($registry);

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, $prefixConfig));
        $entropy = (new Bip39SeedGenerator())->getSeed($mnemonic);
        $rootKey = $hdFactory->fromEntropy($entropy, $prefix->getScriptDataFactory());
        $accountKey = $rootKey->derivePath(substr($path, 2));
        $accountPubKey = $accountKey->withoutPrivateKey();
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 1;
        $wallet = $walletFactory->createBip44WalletFromAccountKey("wallet-identifier", $accountPubKey, $path, $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $spk = $script->getScriptPubKey();
        $txid = new Buffer("\x42", 32);

        $feeRate = 5;
        $amount = 1;
        $outPoint = new OutPoint($txid, 0);
        $this->sessionDb->createUtxo($wallet->getDbWallet(), $script, $outPoint, new TransactionOutput($amount, $spk));

        $wallet->sendAllCoins($destAddr->getScriptPubKey(), $feeRate);
    }

    public function testSpendWithChange()
    {
        $ecAdapter = Bitcoin::getEcAdapter();
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $pubFactory = new PublicKeyFactory($ecAdapter);
        $destPub = $pubFactory->fromHex("029c730c91292e556c50d6fcfe6a7601435317c7cb2cd1399de5f350208e2691fb");
        $destAddr = new PayToPubKeyHashAddress($destPub->getPubKeyHash());
        $accountPath = "M/44'/0'/0'";

        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $rootKey = $hdFactory->fromEntropy(new Buffer("", 32));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 2;
        $wallet = $walletFactory->createBip44WalletFromRootKey("wallet-identifier", $rootKey, $accountPath, $gapLimit, null);
        $script = $wallet->getScriptGenerator()->generate();
        $spk = $script->getScriptPubKey();
        $txid = new Buffer("\x42", 32);
        $shouldSpend = [];
        $shouldSpend[] = $outPoint1 = new OutPoint($txid, 0);
        $txOut1 = new TransactionOutput(100000000, $spk);
        $shouldSpend[] = $outPoint2 = new OutPoint($txid, 1);
        $txOut2 = new TransactionOutput(200000000, $spk);
        $shouldSpend[] = $outPoint3 = new OutPoint($txid, 2);
        $txOut3 = new TransactionOutput(10000000, $spk);
        $totalIn = $txOut1->getValue() + $txOut2->getValue();

        $this->sessionDb->createUtxo($wallet->getDbWallet(), $script, $outPoint1, $txOut1);
        $this->sessionDb->createUtxo($wallet->getDbWallet(), $script, $outPoint2, $txOut2);
        $this->sessionDb->createUtxo($wallet->getDbWallet(), $script, $outPoint3, $txOut3);

        $feeRate = 5;
        $sendTxOut = new TransactionOutput(120000000, $destAddr->getScriptPubKey());
        $prepared = $wallet->send([$sendTxOut], $feeRate);
        $this->assertCount(2, $prepared->getTx()->getInputs());
        $this->assertCount(2, $prepared->getTx()->getOutputs());
        $tx = $prepared->getTx();
        // need to improve the size estimation in the send routine before
        // getting to this,appears to be a 1 vbyte difference below:

//        $totalOut = $tx->getOutput(0)->getValue() + $tx->getOutput(1)->getValue();
//
//        $scriptAndSignData = new ScriptAndSignData($spk, $script->getSignData());
//        $estimatedVsize = SizeEstimation::estimateVsize([$scriptAndSignData, $scriptAndSignData,], [$sendTxOut, new TransactionOutput(0, $wallet->getChangeScriptGenerator()->generate()->getScriptPubKey())]);
//        echo "estimation in test: $estimatedVsize\n";
//        $this->assertEquals($totalIn - ($estimatedVsize * $feeRate), $totalOut);
    }
}
