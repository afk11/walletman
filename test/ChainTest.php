<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Key\PrivateKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\Transaction;
use BitWasp\Bitcoin\Transaction\TransactionInput;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\DbTestCase;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\DB\DbHeader;
use Mdanter\Ecc\Math\GmpMath;

class ChainTest extends DbTestCase
{
    protected $regtest = true;

    public function testAcceptGenesisBlock()
    {
        $genesisHeaderHashHex = $this->sessionChainParams->getGenesisBlockHeader()->getHash()->getHex();

        $chain = new Chain();
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
            $chain->getBestHeaderHash()->getHex()
        );
        $this->assertEquals(0, $chain->getBestHeaderHeight());
        $this->assertEquals(0, $chain->getBestBlockHeight());
    }

    private function makeBlock(BlockHeaderInterface $prevHeader, ScriptInterface $cbScript, TransactionInterface... $otherTxs): BlockInterface
    {
        $prevHash = $prevHeader->getHash();
        $cbOutPoint = new OutPoint(new Buffer('', 32), 0xffffffff);
        $cb1 = new Transaction(1, [new TransactionInput($cbOutPoint, new Script(new Buffer("51")))], [new TransactionOutput(5000000000, $cbScript)]);
        $cb1TxId = $cb1->getTxId();
        return new Block(new Math(), new BlockHeader(1, $prevHash, $cb1TxId, $prevHeader->getBits(), time(), 0), [$cb1]);
    }

    public function testAcceptBlocks()
    {
        $cbPrivKey = PrivateKeyFactory::create(true);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());

        $chain = new Chain();
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $this->assertEquals(0, $chain->getBestHeaderHeight());
        $this->assertEquals(0, $chain->getBestBlockHeight());

        // Add block 1
        $prev = $chain->getBestHeader();
        $block1 = $this->makeBlock($prev, $cbScript);
        $block1Hash = $block1->getHeader()->getHash();
        $chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader());
        $this->assertEquals(1, $chain->getBestHeaderHeight());

        $chain->addNextBlock($this->sessionDb, 1, $block1Hash, $block1);
        $this->assertEquals(1, $chain->getBestBlockHeight());

        // Add block 2
        $prev = $block1->getHeader();
        $block2 = $this->makeBlock($prev, $cbScript);
        $block2Hash = $block2->getHeader()->getHash();
        $chain->acceptHeader($this->sessionDb, $block2Hash, $block2->getHeader());
        $this->assertEquals(2, $chain->getBestHeaderHeight());

        $chain->addNextBlock($this->sessionDb, 2, $block2Hash, $block2);
        $this->assertEquals(2, $chain->getBestBlockHeight());
    }

    public function testChainCanReloadState()
    {
        $cbPrivKey = PrivateKeyFactory::create(true);
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());

        $chain = new Chain();
        $chain->init($this->sessionDb, $this->sessionChainParams);

        // Add block 1
        $prev = $chain->getBestHeader();
        $block1 = $this->makeBlock($prev, $cbScript);
        $block1Hash = $block1->getHeader()->getHash();
        $chain->acceptHeader($this->sessionDb, $block1Hash, $block1->getHeader());
        $this->assertEquals(1, $chain->getBestHeaderHeight());

        $chain->addNextBlock($this->sessionDb, 1, $block1Hash, $block1);
        $this->assertEquals(1, $chain->getBestBlockHeight());

        // Reload and ensure it's the same
        $chain = new Chain();
        $chain->init($this->sessionDb, $this->sessionChainParams);
        $this->assertEquals(1, $chain->getBestHeaderHeight());
        $this->assertEquals($block1Hash->getHex(), $chain->getBestHeaderHash()->getHex());
        $this->assertEquals(1, $chain->getBestBlockHeight());
    }
}
