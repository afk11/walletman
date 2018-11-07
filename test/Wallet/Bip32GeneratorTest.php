<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\Wallet\Bip32Generator;
use BitWasp\Wallet\Wallet\Factory;

class Bip32GeneratorTest extends DbTestCase
{
    public function testBip32Generator()
    {
        $ecAdapter = Bitcoin::getEcAdapter();
        $rootKey = HierarchicalKeyFactory::fromEntropy(new Buffer("", 32), $ecAdapter);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $ecAdapter);

        $wallet = $walletFactory->createBip44WalletFromRootKey("wallet-identifier", $rootKey, "M/44'/0'/0'", null);
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/0"));

        $branchNode = $this->sessionDb->loadKeyByPath($wallet->getDbWallet()->getId(), "M/44'/0'/0'/0", 0);
        $key = $branchNode->getHierarchicalKey($this->sessionNetwork, $ecAdapter);

        // Test first generate with gap limit 1. We get 2 because 0 is missing.
        $generator = new Bip32Generator($this->sessionDb, $branchNode, 1, $key);
        $generator->generate();
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/0"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/1"));
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/2"));
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/3"));

        // generate 1
        $generator->generate();
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/2"));
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/3"));

        // test with gap limit 5
        $branchNode = $this->sessionDb->loadKeyByPath($wallet->getDbWallet()->getId(), "M/44'/0'/0'/0", 0);
        $key = $branchNode->getHierarchicalKey($this->sessionNetwork, $ecAdapter);
        $generator = new Bip32Generator($this->sessionDb, $branchNode, 5, $key);
        $generator->generate();
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/3"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/4"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/5"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/6"));
        $this->assertNotNull($wallet->getScriptByPath("M/44'/0'/0'/0/7"));
        $this->assertNull($wallet->getScriptByPath("M/44'/0'/0'/0/8"));
    }
}
