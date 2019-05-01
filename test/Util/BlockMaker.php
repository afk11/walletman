<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Util;

use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\MerkleRoot;
use BitWasp\Bitcoin\Chain\ParamsInterface;
use BitWasp\Bitcoin\Script\ScriptInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Transaction\Transaction;
use BitWasp\Bitcoin\Transaction\TransactionInput;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Block\BlockHeader;

class BlockMaker
{

    public static function makeBlock(ParamsInterface $params, BlockHeaderInterface $prevHeader, ScriptInterface $cbScript, TransactionInterface... $otherTxs): BlockInterface
    {
        $prevHash = $prevHeader->getHash();
        $cbOutPoint = new OutPoint(new Buffer('', 32), 0xffffffff);
        $cb1 = new Transaction(1, [new TransactionInput($cbOutPoint, new Script(new Buffer("51")))], [new TransactionOutput(5000000000, $cbScript)]);

        $merkle = new MerkleRoot(new Math(), array_merge([$cb1], $otherTxs));
        $merkleRoot = $merkle->calculateHash();

        $pow = new ProofOfWork(new Math(), $params);
        $i = 0;
        do {
            $b = new Block(new Math(), new BlockHeader(1, $prevHash, $merkleRoot, time(), $prevHeader->getBits(), $i++), ...array_merge([$cb1], $otherTxs));
        } while (!$pow->checkHeader($b->getHeader()));

        return $b;
    }
}
