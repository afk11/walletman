<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\Util\BlockMaker;
use BitWasp\Wallet\BlockProcessor;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\DB\DbHeader;

class ChainTest extends DbTestCase
{
    protected $regtest = true;

    public function testAcceptGenesisBlock()
    {
        $genesisHeaderHashHex = $this->sessionChainParams->getGenesisBlockHeader()->getHash()->getHex();

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $dbHeader = $chain->getBestHeader();
        $this->assertEquals(
            $genesisHeaderHashHex,
            $dbHeader->getHash()->getHex()
        );
        $this->assertEquals(
            $genesisHeaderHashHex,
            $chain->getBlockHash(0)->getHex()
        );
        $this->assertEquals(
            $genesisHeaderHashHex,
            $chain->getBestHeader()->getHash()->getHex()
        );
        $this->assertEquals(0, $chain->getBestHeader()->getHeight());
        $this->assertEquals(0, $chain->getBestBlockHeight());
    }

    public function testAcceptBlocks()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());
        $blockProcessor = new BlockProcessor($this->sessionDb, ...[]);

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $genesisHeader = $this->sessionChainParams->getGenesisBlockHeader();
        $genesisHash = $genesisHeader->getHash();
        $this->assertEquals(0, $chain->getBestBlockHeight());
        $bestHeader = $chain->getBestHeader();
        $this->assertEquals(0, $bestHeader->getHeight());
        $this->assertEquals($genesisHash->getHex(), $bestHeader->getHash()->getHex());
        $this->assertEquals($genesisHash->getHex(), $chain->getBlockHash(0)->getHex());

        // Add block 1
        $prev = $chain->getBestHeader()->getHeader();
        $block1 = BlockMaker::makeBlock($this->sessionChainParams, $prev, $cbScript);
        $block1Hash = $block1->getHeader()->getHash();
        $header1 = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader(), $header1));
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block1Hash->getHex(), $chain->getBlockHash(1)->getHex());

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block1Hash, $block1));
        $this->assertEquals(1, $chain->getBestBlockHeight());

        // Add block 2
        $prev = $block1->getHeader();
        $block2 = BlockMaker::makeBlock($this->sessionChainParams, $prev, $cbScript);
        $block2Hash = $block2->getHeader()->getHash();
        $header2 = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block2Hash, $block2->getHeader(), $header2));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2Hash->getHex(), $chain->getBlockHash(2)->getHex());

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block2Hash, $block2));
        $this->assertEquals(2, $chain->getBestBlockHeight());
    }

    public function testDuplicateAcceptHeaderReturnsTrue()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $this->assertEquals(0, $chain->getBestHeader()->getHeight());
        $this->assertEquals(0, $chain->getBestBlockHeight());

        // Add block 1
        $prev = $chain->getBestHeader()->getHeader();
        $block1 = BlockMaker::makeBlock($this->sessionChainParams, $prev, $cbScript);
        $block1Hash = $block1->getHeader()->getHash();
        $header1 = null;
        $header1Again = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader(), $header1));
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader(), $header1Again));
        $this->assertEquals($header1->getHash(), $chain->getBestHeader()->getHash());
        $this->assertEquals($header1Again->getHash(), $chain->getBestHeader()->getHash());
    }

    public function testChainCanReloadState()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());
        $blockProcessor = new BlockProcessor($this->sessionDb, ...[]);
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        // Add block 1
        $prev = $chain->getBestHeader();
        $block1 = BlockMaker::makeBlock($this->sessionChainParams, $prev->getHeader(), $cbScript);
        $block1Hash = $block1->getHeader()->getHash();
        $header1 = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader(), $header1));
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block1Hash, $block1));
        $this->assertEquals(1, $chain->getBestBlockHeight());

        // Reload and ensure it's the same
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block1Hash->getHex(), $chain->getBestHeader()->getHash()->getHex());
        $this->assertEquals(1, $chain->getBestBlockHeight());
        $this->assertEquals($block1Hash->getHex(), $chain->getBestBlock()->getHash()->getHex());
    }

    public function testChainDeterminesHeaderAndBlockChain()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $genesis = $chain->getBestHeader();

        // Add header 1
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript);
        $block1aHash = $block1a->getHeader()->getHash();
        $header1a = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1aHash, $block1a->getHeader(), $header1a));

        // Reload and ensure it's the same
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block1aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());
        $this->assertEquals(0, $chain->getBestBlockHeight());
        $this->assertEquals($genesis->getHash()->getHex(), $chain->getBestBlock()->getHash()->getHex());
    }

    public function testChainInitDeterminesWork()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());
        $cbPrivKey2 = $privKeyFactory->generateCompressed($random);
        $cbScript2 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2->getPubKeyHash());
        $blockProcessor = new BlockProcessor($this->sessionDb, ...[]);
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();
        // Add header 1a
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript);
        $block1aHash = $block1a->getHeader()->getHash();
        $header1a = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1aHash, $block1a->getHeader(), $header1a));
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block1aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block1aHash, $block1a));

        // Add header 2a
        $block2a = BlockMaker::makeBlock($this->sessionChainParams, $block1a->getHeader(), $cbScript);
        $block2aHash = $block2a->getHeader()->getHash();
        $header2a = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block2aHash, $block2a->getHeader(), $header2a));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block2aHash, $block2a));

        // Add header 1b
        $block1b = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript2);
        $block1bHash = $block1b->getHeader()->getHash();
        $header1b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1bHash, $block1b->getHeader(), $header1b));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add header 2b
        $block2b = BlockMaker::makeBlock($this->sessionChainParams, $block1b->getHeader(), $cbScript2);
        $block2bHash = $block2b->getHeader()->getHash();
        $header2b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block2bHash, $block2b->getHeader(), $header2b));

        // Add header 3b
        $block3b = BlockMaker::makeBlock($this->sessionChainParams, $block2b->getHeader(), $cbScript2);
        $block3bHash = $block3b->getHeader()->getHash();
        $header3b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block3bHash, $block3b->getHeader(), $header3b));
        $this->assertEquals(3, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Reload and ensure it picked 3b
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);

        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(3, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestHeader()->getHash()->getHex());
        $this->assertEquals(2, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
    }

    public function testChainAcceptsHeaderReorg()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());
        $cbPrivKey2 = $privKeyFactory->generateCompressed($random);
        $cbScript2 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2->getPubKeyHash());

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();
        // Add header 1a
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript);
        $block1aHash = $block1a->getHeader()->getHash();
        $header1a = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1aHash, $block1a->getHeader(), $header1a));
        $this->assertEquals(1, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block1aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add header 2a
        $block2a = BlockMaker::makeBlock($this->sessionChainParams, $block1a->getHeader(), $cbScript);
        $block2aHash = $block2a->getHeader()->getHash();
        $header2a = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block2aHash, $block2a->getHeader(), $header2a));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add header 1b
        $block1b = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript2);
        $block1bHash = $block1b->getHeader()->getHash();
        $header1b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block1bHash, $block1b->getHeader(), $header1b));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add header 2b
        $block2b = BlockMaker::makeBlock($this->sessionChainParams, $block1b->getHeader(), $cbScript2);
        $block2bHash = $block2b->getHeader()->getHash();
        $header2b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block2bHash, $block2b->getHeader(), $header2b));
        $this->assertEquals(2, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add header 3b
        $block3b = BlockMaker::makeBlock($this->sessionChainParams, $block2b->getHeader(), $cbScript2);
        $block3bHash = $block3b->getHeader()->getHash();
        $header3b = null;
        $this->assertTrue($chain->acceptHeader($this->sessionDb, $block3bHash, $block3b->getHeader(), $header3b));
        $this->assertEquals(3, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Reload and ensure it picked 3b
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);

        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(3, $chain->getBestHeader()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestHeader()->getHash()->getHex());
    }

    public function testChainAcceptsBlockChainReorg()
    {
        $random = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed($random);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());
        $cbPrivKey2 = $privKeyFactory->generateCompressed($random);
        $cbScript2 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2->getPubKeyHash());
        $blockProcessor = new BlockProcessor($this->sessionDb, ...[]);

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();
        // Add block 1a
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript);
        $block1aHash = $block1a->getHeader()->getHash();
        $header1a = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block1aHash, $block1a));
        $this->assertEquals(1, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block1aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
        $this->assertEquals($block1aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add block 2a
        $block2a = BlockMaker::makeBlock($this->sessionChainParams, $block1a->getHeader(), $cbScript);
        $block2aHash = $block2a->getHeader()->getHash();
        $header2a = null;
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block2aHash, $block2a));
        $this->assertEquals(2, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add block 1b - should be no disconnects/connects, just save
        $block1b = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript2);
        $block1bHash = $block1b->getHeader()->getHash();
        $header1b = null;
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block1bHash, $block1b));
        $this->assertEquals(2, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add block 2b - should be no disconnects/connects, just save
        $block2b = BlockMaker::makeBlock($this->sessionChainParams, $block1b->getHeader(), $cbScript2);
        $block2bHash = $block2b->getHeader()->getHash();
        $header2b = null;
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block2bHash, $block2b));

        $this->assertEquals(2, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
        $this->assertEquals($block2aHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Add block 3b
        $block3b = BlockMaker::makeBlock($this->sessionChainParams, $block2b->getHeader(), $cbScript2);
        $block3bHash = $block3b->getHeader()->getHash();
        $header3b = null;
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $blockProcessor, $block3bHash, $block3b));
        $this->assertEquals(3, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestHeader()->getHash()->getHex());

        // Reload and ensure it picked 3b
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);

        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(3, $chain->getBestBlock()->getHeight());
        $this->assertEquals($block3bHash->getHex(), $chain->getBestBlock()->getHash()->getHex());
    }

    // todo: write block reorg test

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage FATAL: could not find prev block
     */
    public function testMissingPrevBlockCausesInitFailure()
    {
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);

        $prev = '424a424a424a424a424a424a424a424a424a424a424a424a424a424a424a424a';
        $merkle = '9292929292929292929292929292929292929292929292929292929292929292';
        $block = new BlockHeader(
            1,
            Buffer::hex($prev),
            Buffer::hex($merkle),
            123123123123,
            0x1d00ffff,
            1
        );

        $insertStmt = $this->sessionDb->getPdo()->prepare("INSERT INTO header (status, height, work, hash, version, prevBlock, merkleRoot, time, nbits, nonce) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([
            DbHeader::HEADER_VALID|DbHeader::HEADER_VALID,
            1,
            '2',
            $block->getHash()->getHex(),
            $block->getVersion(),
            $block->getPrevBlock()->getHex(),
            $block->getMerkleRoot()->getHex(),
            $block->getTimestamp(),
            $block->getBits(),
            $block->getNonce(),
        ]);

        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage No chain header with height 9999
     */
    public function testGetBlockHashErrorsIfHeightTooBig()
    {
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $chain->getBlockHash(9999);
    }

    public function testInitWithWrongGenesisBlockCausesError()
    {
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Database has different genesis hash!");

        $chain = new Chain($pow);
        $chain->init($this->sessionDb, new Params(new Math()));
    }
}
