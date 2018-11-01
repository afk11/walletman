<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\DB\DbWallet;
use BitWasp\Wallet\Wallet\Factory;

class WalletTest extends DbTestCase
{
    protected $regtest = true;

    private function insertTx(\PDO $pdo, DbWallet $dbWallet, string $txid, int $valueChange)
    {
        $stmt = $pdo->prepare("INSERT INTO tx (walletId, txid, valueChange) VALUES (?, ?, ?)");
        $stmt->execute([
            $dbWallet->getId(), $txid, $valueChange,
        ]);
    }

    public function testConfirmedBalanceEmpty()
    {
        $pdo = $this->sessionDb->getPdo();
        $ecAdapter = Bitcoin::getEcAdapter();
        $rootKey = HierarchicalKeyFactory::fromEntropy(new Buffer("", 32), $ecAdapter);
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, $ecAdapter);

        $wallet = $walletFactory->createBip44WalletFromRootKey("wallet-identifier", $rootKey, "M/44'/0'/0'", null);
        $this->assertEquals(0, $wallet->getConfirmedBalance());

        $oneBtc = 100000000;
        $this->insertTx($pdo, $wallet->getDbWallet(), "abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234", $oneBtc);

        $wallet = $walletFactory->loadWallet("wallet-identifier");
        $this->assertEquals($oneBtc, $wallet->getConfirmedBalance());
    }
}
