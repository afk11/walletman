<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Key\Factory\PublicKeyFactory;
use BitWasp\Bitcoin\Key\KeyToScript\ScriptAndSignData;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Transaction\Factory\SignData;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\Transaction;
use BitWasp\Bitcoin\Transaction\TransactionInput;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Test\Wallet\TestCase;
use BitWasp\Wallet\Wallet\PreparedTx;

class PreparedTxTest extends TestCase
{
    public function testGetValues()
    {
        $adapter = Bitcoin::getEcAdapter();
        $privKeyFactory = new PrivateKeyFactory($adapter);
        $pubKeyFactory = new PublicKeyFactory($adapter);

        $pubKey1 = $pubKeyFactory->fromHex("03a8637bc01b336f5d1c68fc2da88a889dc17f63ca91bba0627a23e6da9f812533");
        $p2pkh1 = ScriptFactory::scriptPubKey()->p2pkh($pubKey1->getPubKeyHash());

        $privKey2 = $privKeyFactory->fromWif("Kxi353XBGNLDvvuWVkkDqZprFYBESm8WuoM8cnbLhPQ6C5rvVeKL");
        $pubKey2 = $privKey2->getPublicKey();
        $p2pkh2 = ScriptFactory::scriptPubKey()->p2pkh($pubKey2->getPubKeyHash());

        $privKey3 = $privKeyFactory->fromWif("KxgsQo9UHFGnSmQjBQASTXbthCrUAxmbNZYsegfQrvozxXRdb2he");
        $pubKey3 = $privKey3->getPublicKey();
        $p2pkh3 = ScriptFactory::scriptPubKey()->p2pkh($pubKey3->getPubKeyHash());

        $tx = new Transaction(
            1,
            [
                new TransactionInput(new OutPoint(new Buffer("\x01", 32), 0), new Script()),
                new TransactionInput(new OutPoint(new Buffer("\x01", 32), 0), new Script()),
            ],
            [
                new TransactionOutput(1, $p2pkh1),
            ]
        );

        $txOuts = [
            new TransactionOutput(100000000, $p2pkh2),
            new TransactionOutput(100000000, $p2pkh3),
        ];
        $scripts = [
            new SignData(),
            new SignData(),
        ];
        $keyIdentifiers = [
            'identifier1',
            'identifier2',
        ];

        $prepared = new PreparedTx($tx, $txOuts, $scripts, $keyIdentifiers);
        $this->assertSame($tx, $prepared->getTx());
        $this->assertSame($txOuts[0], $prepared->getTxOut(0));
        $this->assertSame($txOuts[1], $prepared->getTxOut(1));
        $this->assertSame($scripts[0], $prepared->getSignData(0));
        $this->assertSame($scripts[1], $prepared->getSignData(1));
        $this->assertSame($keyIdentifiers[0], $prepared->getKeyIdentifier(0));
        $this->assertSame($keyIdentifiers[1], $prepared->getKeyIdentifier(1));

        $this->assertEquals(\LogicException::class, (function () use ($prepared) {
            try {
                $prepared->getTxOut(999);
            } catch (\LogicException $e) {
                return \LogicException::class;
            }
            return null;
        })());

        $this->assertEquals(\LogicException::class, (function () use ($prepared) {
            try {
                $prepared->getSignData(999);
            } catch (\LogicException $e) {
                return \LogicException::class;
            }
            return null;
        })());

        $this->assertEquals(\LogicException::class, (function () use ($prepared) {
            try {
                $prepared->getKeyIdentifier(999);
            } catch (\LogicException $e) {
                return \LogicException::class;
            }
            return null;
        })());

        // scripts and key ids can be nulled
        $prepared = new PreparedTx($tx, $txOuts, [], []);
        $this->assertNull($prepared->getSignData(0));
        $this->assertNull($prepared->getSignData(1));
        $this->assertNull($prepared->getKeyIdentifier(0));
        $this->assertNull($prepared->getKeyIdentifier(1));
    }
}
