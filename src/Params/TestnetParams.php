<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Params;

use BitWasp\Bitcoin\Block\Block;
use BitWasp\Bitcoin\Block\BlockHeader;
use BitWasp\Bitcoin\Block\BlockHeaderInterface;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Buffertools\Buffer;

class TestnetParams extends Params
{
    protected static $coinbaseMaturityAge = 120;

    protected static $p2shActivateTime = 1333238400;

    protected static $powTargetTimespan = 1209600;

    protected static $powTargetSpacing = 600;

    protected static $powRetargetInterval = 2016;

    protected static $majorityWindow = 1000;

    protected static $majorityEnforceBlockUpgrade = 750;

    public function getGenesisBlockHeader(): BlockHeaderInterface
    {
        return new BlockHeader(
            1,
            new Buffer('', 32),
            Buffer::hex('4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b', 32),
            1231006505,
            0x1d00ffff,
            2083236893
        );
    }

    public function getGenesisBlock(): BlockInterface
    {
        $timestamp = new Buffer('The Times 03/Jan/2009 Chancellor on brink of second bailout for banks', null, $this->math);
        $publicKey = Buffer::hex('04678afdb0fe5548271967f1a67130b7105cd6a828e03909a67962e0ea1f61deb649f6bc3f4cef38c4f35504e51ec112de5c384df7ba0b8d578a4c702b6bf11d5f', null, $this->math);

        $inputScript = ScriptFactory::sequence([
            Buffer::int('486604799', 4, $this->math)->flip(),
            Buffer::int('4', null, $this->math),
            $timestamp
        ]);

        $outputScript = ScriptFactory::sequence([$publicKey, Opcodes::OP_CHECKSIG]);

        return new Block(
            $this->math,
            $this->getGenesisBlockHeader(),
            [
                (new TxBuilder())
                    ->version('1')
                    ->input(new Buffer('', 32), 0xffffffff, $inputScript)
                    ->output(5000000000, $outputScript)
                    ->locktime(0)
                    ->get()
            ]
        );
    }
}
