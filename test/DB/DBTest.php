<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\DB;

use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\DB\DbHeader;
use BitWasp\Wallet\DB\DbWalletTx;

class DBTest extends DbTestCase
{
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
}
