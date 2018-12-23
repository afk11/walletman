<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\DB\DbScript;
use BitWasp\Wallet\Wallet\Bip32Generator;
use BitWasp\Wallet\Wallet\Bip32ScriptStorage;
use BitWasp\Wallet\Wallet\Bip44Wallet;
use BitWasp\Wallet\Wallet\Factory;

class Bip32ScriptStorageTest extends DbTestCase
{
    public function testDerivesGapLimitAfterLastUsedAddress()
    {
        $ecAdapter = Bitcoin::getEcAdapter();
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter));
        $hdFactory = new HierarchicalKeyFactory($ecAdapter);
        $rootKey = $hdFactory->fromEntropy(new Buffer("", 32));
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $hdSerializer, $ecAdapter);

        $gapLimit = 100;
        /** @var Bip44Wallet $wallet */
        $wallet = $walletFactory->createBip44WalletFromRootKey("wallet-identifier", $rootKey, "M/44'/0'/0'", $gapLimit, null);
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/0"));

        $dbWallet = $wallet->getDbWallet();
        $branchNode = $this->sessionDb->loadKeyByPath($dbWallet->getId(), "M/44'/0'/0'/0", 0);
        $key = $branchNode->getHierarchicalKey($this->sessionNetwork, $ecAdapter);

        $generator = new Bip32Generator($this->sessionDb, $branchNode, 2, $key);
        $generator->generate();
        $last = $wallet->getScriptByPath("M/44'/0'/0'/0/2");
        $this->assertNotNull($last);
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/3"));

        // test with gap limit 5
        $storage = new Bip32ScriptStorage($this->sessionDb, $hdSerializer, $dbWallet, 5, $ecAdapter, $this->sessionNetwork);
        $this->assertNull($storage->searchScript(new Script()));

        $foundLast = $storage->searchScript($last->getScriptPubKey());
        $this->assertInstanceOf(DbScript::class, $foundLast);
        $this->assertEquals($last->getId(), $foundLast->getId());

        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/3"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/4"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/5"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/6"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/7"));
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/8"));
    }
}
