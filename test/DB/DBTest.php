<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DBInterface;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWalletTx;

class DBTest extends DbTestCase
{
    private function loadWalletUtxo(DBInterface $db, int $walletId, string $txid, int $vout)
    {
        $q = $db->getPdo()->query("SELECT * FROM utxo where walletId = ? and txid = ? and vout = ?");
        $q->execute([$walletId, $txid, $vout]);
        return $q->fetchObject(DbUtxo::class);
    }

    public function testGetBlockHash()
    {
        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $work = $pow->getWork($genesisHeader->getBits());

        $insertBlock = $this->sessionDb->getPdo()->prepare(
            "INSERT INTO header (status, height, work, hash, version, prevBlock, merkleRoot, time, nbits, nonce) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $merkle = $genesisHeader->getMerkleRoot()->getHex();
        $prevHash = $genesisHeader->getPrevBlock()->getHex();
        $this->assertTrue($insertBlock->execute([
            DbHeader::HEADER_VALID, 0, gmp_strval($work, 10),
            $genesisHash->getHex(), $genesisHeader->getVersion(), $prevHash,
            $merkle, $genesisHeader->getTimestamp(), $genesisHeader->getBits(),
            $genesisHeader->getNonce(),
        ]));

        /** @var DbHeader $index */
        $index = $this->sessionDb->getHeader($genesisHash);
        $this->assertInstanceOf(DbHeader::class, $index);
        $this->assertEquals($genesisHash->getHex(), $index->getHash()->getHex());
        $this->assertEquals(0, $index->getHeight());
        $this->assertEquals($work, $index->getWork());
        $this->assertEquals($genesisHeader->getVersion(), $index->getHeader()->getVersion());
        $this->assertEquals($prevHash, $index->getHeader()->getPrevBlock()->getHex());
        $this->assertEquals($merkle, $index->getHeader()->getMerkleRoot()->getHex());
        $this->assertEquals($genesisHeader->getBits(), $index->getHeader()->getBits());
        $this->assertEquals($genesisHeader->getTimestamp(), $index->getHeader()->getTimestamp());
        $this->assertEquals($genesisHeader->getNonce(), $index->getHeader()->getNonce());
    }

    public function testGetGenesisHeader()
    {
        $this->assertNull($this->sessionDb->getGenesisHeader());

        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $work = $pow->getWork($genesisHeader->getBits());

        $insertBlock = $this->sessionDb->getPdo()->prepare(
            "INSERT INTO header (status, height, work, hash, version, prevBlock, merkleRoot, time, nbits, nonce) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $merkle = $genesisHeader->getMerkleRoot()->getHex();
        $prevHash = $genesisHeader->getPrevBlock()->getHex();
        $this->assertTrue($insertBlock->execute([
            DbHeader::HEADER_VALID, 0, gmp_strval($work, 10),
            $genesisHash->getHex(), $genesisHeader->getVersion(), $prevHash,
            $merkle, $genesisHeader->getTimestamp(), $genesisHeader->getBits(),
            $genesisHeader->getNonce(),
        ]));

        /** @var DbHeader $index */
        $index = $this->sessionDb->getGenesisHeader();
        $this->assertInstanceOf(DbHeader::class, $index);
        $this->assertEquals($genesisHash->getHex(), $index->getHash()->getHex());
        $this->assertEquals(0, $index->getHeight());
        $this->assertEquals($work, $index->getWork());
        $this->assertEquals($genesisHeader->getVersion(), $index->getHeader()->getVersion());
        $this->assertEquals($prevHash, $index->getHeader()->getPrevBlock()->getHex());
        $this->assertEquals($merkle, $index->getHeader()->getMerkleRoot()->getHex());
        $this->assertEquals($genesisHeader->getBits(), $index->getHeader()->getBits());
        $this->assertEquals($genesisHeader->getTimestamp(), $index->getHeader()->getTimestamp());
        $this->assertEquals($genesisHeader->getNonce(), $index->getHeader()->getNonce());
    }

    public function testAddHeader()
    {
        $this->assertNull($this->sessionDb->getGenesisHeader());

        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $work = $pow->getWork($genesisHeader->getBits());
        $this->assertTrue($this->sessionDb->addHeader(0, $work, $genesisHeader->getHash(), $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID));

        $merkle = $genesisHeader->getMerkleRoot()->getHex();
        $prevHash = $genesisHeader->getPrevBlock()->getHex();

        /** @var DbHeader $index */
        $index = $this->sessionDb->getGenesisHeader();
        $this->assertInstanceOf(DbHeader::class, $index);
        $this->assertEquals($genesisHash->getHex(), $index->getHash()->getHex());
        $this->assertEquals(0, $index->getHeight());
        $this->assertEquals($work, $index->getWork());
        $this->assertEquals($genesisHeader->getVersion(), $index->getHeader()->getVersion());
        $this->assertEquals($prevHash, $index->getHeader()->getPrevBlock()->getHex());
        $this->assertEquals($merkle, $index->getHeader()->getMerkleRoot()->getHex());
        $this->assertEquals($genesisHeader->getBits(), $index->getHeader()->getBits());
        $this->assertEquals($genesisHeader->getTimestamp(), $index->getHeader()->getTimestamp());
        $this->assertEquals($genesisHeader->getNonce(), $index->getHeader()->getNonce());
    }

    public function testMarkBirthdayHistoryValid()
    {
        $this->assertNull($this->sessionDb->getGenesisHeader());

        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $work = $pow->getWork($genesisHeader->getBits());
        $this->assertTrue($this->sessionDb->addHeader(0, $work, $genesisHeader->getHash(), $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID));

        $header1 = new BlockHeader(1, $genesisHash, new Buffer("", 32), 1, 1, 1);
        $hash1 = $header1->getHash();
        $this->assertTrue($this->sessionDb->addHeader(1, gmp_mul(2, $work), $hash1, $header1, DbHeader::HEADER_VALID));

        /** @var DbHeader $index1 */
        $index1 = $this->sessionDb->getHeader($hash1);
        $this->assertInstanceOf(DbHeader::class, $index1);
        $this->assertEquals(DbHeader::HEADER_VALID, $index1->getStatus());
        $this->sessionDb->markBirthdayHistoryValid(1);

        /** @var DbHeader $index1 */
        $index1 = $this->sessionDb->getHeader($hash1);
        $this->assertEquals(DbHeader::HEADER_VALID|DbHeader::BLOCK_VALID, $index1->getStatus());
    }

    public function testSetBlockReceived()
    {
        $this->assertNull($this->sessionDb->getGenesisHeader());

        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $work = $pow->getWork($genesisHeader->getBits());
        $this->assertTrue($this->sessionDb->addHeader(0, $work, $genesisHeader->getHash(), $genesisHeader, DbHeader::HEADER_VALID | DbHeader::BLOCK_VALID));

        $header1 = new BlockHeader(1, $genesisHash, new Buffer("", 32), 1, 1, 1);
        $hash1 = $header1->getHash();

        $this->assertTrue($this->sessionDb->addHeader(1, gmp_mul(2, $work), $hash1, $header1, DbHeader::HEADER_VALID));

        /** @var DbHeader $index1 */
        $index1 = $this->sessionDb->getHeader($hash1);
        $this->assertInstanceOf(DbHeader::class, $index1);
        $this->assertEquals(DbHeader::HEADER_VALID, $index1->getStatus());
        $this->sessionDb->setBlockReceived($hash1);

        /** @var DbHeader $index1 */
        $index1 = $this->sessionDb->getHeader($hash1);
        $this->assertEquals(DbHeader::HEADER_VALID|DbHeader::BLOCK_VALID, $index1->getStatus());
    }

    public function testLoadWallets()
    {
        $this->assertEquals([], $this->sessionDb->loadAllWallets());
    }

    public function testCheckWalletExists()
    {
        $this->assertFalse($this->sessionDb->checkWalletExists("some-non-existent-wallet"));
    }

    public function testCreateAndListTxs()
    {
        $walletId = 1;
        $txid = new Buffer("txid", 32);
        $valueChange = -100000000;
        $this->assertTrue($this->sessionDb->createTx($walletId, $txid, $valueChange, DbWalletTx::STATUS_UNCONFIRMED, null, null));

        $stmt = $this->sessionDb->getTransactions($walletId);
        $tx = $stmt->fetchObject(DbWalletTx::class);
        $this->assertInstanceOf(DbWalletTx::class, $tx);
        /** @var DbWalletTx $tx */
        $this->assertEquals($walletId, $tx->getWalletId());
        $this->assertEquals($txid->getHex(), $tx->getTxId()->getHex());
        $this->assertEquals($valueChange, $tx->getValueChange());
        $this->assertEquals(DbWalletTx::STATUS_UNCONFIRMED, $tx->getStatus());
        $this->assertNull($tx->getConfirmedHash());
        $this->assertNull($tx->getConfirmedHeight());
    }

    public function testCreateUtxo()
    {
        $walletId = 2;
        $txidReceive = new Buffer("txid2", 32);
        $outpointReceive = new OutPoint($txidReceive, 0);
        $txoutReceive = new TransactionOutput(500000, new Script());
        $valueChange = 500000;
        $this->assertTrue($this->sessionDb->createTx($walletId, $txidReceive, $valueChange, DbWalletTx::STATUS_CONFIRMED, null, null));
        $this->sessionDb->createUtxo($walletId, 1, $outpointReceive, $txoutReceive);

        // first utxo is SPENT, spend utxo exists
        $getUtxo = $this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), 0);
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);
        $this->assertEquals($walletId, $getUtxo->getWalletId());
        $this->assertEquals($txoutReceive->getValue(), $getUtxo->getValue());
        $this->assertEquals($txoutReceive->getValue(), $getUtxo->getTxOut()->getValue());
        $this->assertEquals($txoutReceive->getScript()->getHex(), $getUtxo->getTxOut()->getScript()->getHex());
        $this->assertEquals($outpointReceive->getTxId()->getHex(), $getUtxo->getOutPoint()->getTxId()->getHex());
        $this->assertEquals($outpointReceive->getVout(), $getUtxo->getOutPoint()->getVout());
        $this->assertNull($getUtxo->getSpendOutPoint());
    }

    public function testDeleteUtxo()
    {
        $walletId = 2;
        $txidReceive = new Buffer("txid1", 32);
        $outpointReceive = new OutPoint($txidReceive, 0);
        $txoutReceive = new TransactionOutput(500000, new Script());
        $valueChange = 500000;
        $this->assertTrue($this->sessionDb->createTx($walletId, $txidReceive, $valueChange, DbWalletTx::STATUS_CONFIRMED, null, null));

        $this->sessionDb->createUtxo($walletId, 1, $outpointReceive, $txoutReceive);
        $this->assertInstanceOf(DbUtxo::class, $this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), 0));

        $this->sessionDb->deleteUtxo($walletId, $txidReceive, 0);
        $this->assertFalse($this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), 0));
    }

    public function testMarkUtxoSpent()
    {
        $walletId = 2;
        $txidReceive = new Buffer("txid1", 32);
        $txidSpend = new Buffer("txid2", 32);
        $outpointReceive = new OutPoint($txidReceive, 129);
        $txoutReceive = new TransactionOutput(500000, new Script());
        $valueChange = 500000;
        $this->assertTrue($this->sessionDb->createTx($walletId, $txidReceive, $valueChange, DbWalletTx::STATUS_CONFIRMED, null, null));

        $this->sessionDb->createUtxo($walletId, 1, $outpointReceive, $txoutReceive);
        $this->assertInstanceOf(DbUtxo::class, $this->sessionDb->searchUnspentUtxo($walletId, $outpointReceive));

        $this->sessionDb->markUtxoSpent($walletId, $outpointReceive, $txidSpend, 0);
        $this->assertNull($this->sessionDb->searchUnspentUtxo($walletId, $outpointReceive));

        $getUtxo = $this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), $outpointReceive->getVout());
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);
        /** @var DbUtxo $getUtxo */
        $this->assertInstanceOf(OutPointInterface::class, $getUtxo->getSpendOutPoint());
        $this->assertEquals($txidSpend->getHex(), $getUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $getUtxo->getSpendOutPoint()->getVout());
    }

    public function testUnspendTxUtxos()
    {
        // mimics replacement of a confirmed transaction (via reorg or something)
        // except the test doesn't add a replacement

        $walletId = 3;
        $scriptId = 9;
        $txidReceive = new Buffer("txid1", 32);
        $outpointReceive = new OutPoint($txidReceive, 0);
        $txoutReceive = new TransactionOutput(500000, new Script());

        $txidSpend = new Buffer("txid2", 32);
        $outpointSpend = new OutPoint($txidSpend, 0);
        $txoutSpend = new TransactionOutput(400000, new Script());

        // create tx, and utxo
        $this->assertTrue($this->sessionDb->createTx($walletId, $txidReceive, $txoutReceive->getValue(), DbWalletTx::STATUS_CONFIRMED, null, null));
        $this->sessionDb->createUtxo($walletId, $scriptId, $outpointReceive, $txoutReceive);

        // check it exists
        $getUtxo = $this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), 0);
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);

        // create spend tx, delete prev utxo, create newer one
        $this->assertTrue($this->sessionDb->createTx($walletId, $txidSpend, -100000, DbWalletTx::STATUS_CONFIRMED, null, null));
        $this->sessionDb->markUtxoSpent($walletId, $outpointReceive, $txidSpend, 0);
        $this->sessionDb->createUtxo($walletId, $scriptId, $outpointSpend, $txoutSpend);

        // first utxo is SPENT, spend utxo exists
        $getUtxo = $this->loadWalletUtxo($this->sessionDb, $walletId, $txidReceive->getHex(), 0);
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);
        /** @var DbUtxo $getUtxo */
        $this->assertNotNull($getUtxo->getSpendOutPoint());
        $this->assertEquals($txidSpend->getHex(), $getUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $getUtxo->getSpendOutPoint()->getVout());

        // check this query filters spent utxos
        $getUtxo = $this->sessionDb->searchUnspentUtxo($walletId, $outpointReceive);
        $this->assertNull($getUtxo);

        // check it's status now
        $getUtxo = $this->loadWalletUtxo($this->sessionDb, $walletId, $txidSpend->getHex(), 0);
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);

        $this->sessionDb->unspendTxUtxos($txidSpend, [$walletId]);

        $getUtxo = $this->sessionDb->searchUnspentUtxo($walletId, $outpointReceive);
        $this->assertInstanceOf(DbUtxo::class, $getUtxo);
    }
}
