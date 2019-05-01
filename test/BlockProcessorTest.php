<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Transaction\OutPointInterface;
use BitWasp\Bitcoin\Transaction\Transaction;
use BitWasp\Bitcoin\Transaction\TransactionInput;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\BufferInterface;
use BitWasp\Test\Wallet\Util\BlockMaker;
use BitWasp\Wallet\BlockProcessor;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\DB\DbUtxo;
use BitWasp\Wallet\DB\DbWalletTx;
use BitWasp\Wallet\Wallet\Factory;

class BlockProcessorTest extends DbTestCase
{
    protected $regtest = true;

    private function loadTransaction(int $walletId, BufferInterface $txid): DbWalletTx
    {
        $query = $this->sessionDb->getPdo()->query("select * from tx where walletId = ? and txid = ?");
        if (!$query->execute([$walletId, $txid->getHex()])) {
            throw new \RuntimeException("cannot load expected transaction " . $txid->getHex());
        }
        $obj = $query->fetchObject(DbWalletTx::class);
        if (!$obj) {
            throw new \RuntimeException("no tx returned: {$txid->getHex()}");
        }
        return $obj;
    }

    private function getUtxoCount(): int
    {
        $query = $this->sessionDb->getPdo()->query("select count(*) from utxo where `spentTxid` IS NULL and `spentIdx` IS NULL");

        if (!$query->execute()) {
            throw new \RuntimeException("failed to execute query");
        }
        $obj = $query->fetchColumn(0);
        return (int) $obj;
    }

    private function getTransactionCount(): int
    {
        $query = $this->sessionDb->getPdo()->query("select count(*) from tx");
        if (!$query->execute()) {
            throw new \RuntimeException("failed to execute query");
        }
        $obj = $query->fetchColumn(0);
        return (int) $obj;
    }

    private function getWalletTransactionCount(int $walletId): int
    {
        $query = $this->sessionDb->getPdo()->query("select count(*) from tx where walletId = ?");
        if (!$query->execute([$walletId])) {
            throw new \RuntimeException("failed to execute query");
        }
        $obj = $query->fetchColumn(0);
        return (int) $obj;
    }

    private function loadRawUtxo(int $walletId, OutPointInterface $outPoint): DbUtxo
    {
        $query = $this->sessionDb->getPdo()->query("select * from utxo where walletId = ? and txid = ? AND vout = ?");
        if (!$query->execute([$walletId, $outPoint->getTxId()->getHex(), $outPoint->getVout()])) {
            throw new \RuntimeException("cannot load expected utxo {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}");
        }
        $obj = $query->fetchObject(DbUtxo::class);
        if (!$obj) {
            throw new \RuntimeException("no utxo returned");
        }
        return $obj;
    }

    public function testProcessBlockCoinbasePaymentToWallet()
    {
        $lines = explode("\n", file_get_contents(__DIR__ . "/sql/test_wallet.sql"));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line != "") {
                $this->sessionDb->getPdo()->exec($line) or die("sorry, query failed: $line");
            }
        }

        $ec = Bitcoin::getEcAdapter();
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ec)), $ec);
        $wallet = $walletFactory->loadWallet("bip44");
        $walletId = $wallet->getDbWallet()->getId();
        $processor = new BlockProcessor($this->sessionDb, $wallet);

        // first from wallet
        $cbScript = ScriptFactory::fromHex("76a9145947fbf644461dd030a795469721042a96a572aa88ac");
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();

        // another tx, demonstrate we filter block data
        $anotherBlock1Tx = new Transaction(
            1,
            [new TransactionInput(new OutPoint(new Buffer('01', 32), 0), new Script())],
            [new TransactionOutput(1, new Script(new Buffer('1'))), new TransactionOutput(2, new Script(new Buffer('2'))),]
        );

        // Add block 1a
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript, $anotherBlock1Tx);
        $block1aHash = $block1a->getHeader()->getHash();
        $cbTx1 = $block1a->getTransaction(0);
        $header1a = null;

        // check: no transactions exist in the database
        $this->assertEquals(0, $this->getTransactionCount());
        $this->assertEquals(0, $this->getUtxoCount());

        // accept & saveblock
        $this->assertTrue($chain->acceptBlock($this->sessionDb, $block1aHash, $block1a));
        $processor->saveBlock(1, $block1aHash, $block1a);

        // check: one wallet received 1 transaction, despite two being in the block:
        $this->assertEquals(1, $this->getTransactionCount());
        $this->assertEquals(1, $this->getWalletTransactionCount(10001));
        $this->assertEquals(0, $this->getUtxoCount());

        // check transaction: rejected, details otherwise correct
        $tx = $this->loadTransaction(10001, $cbTx1->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_REJECT, $tx->getStatus());
        $this->assertEquals(5000000000, $tx->getValueChange());
        $this->assertEquals(10001, $tx->getWalletId());
        $this->assertEquals(1, $tx->getConfirmedHeight());
        $this->assertEquals($block1aHash->getHex(), $tx->getConfirmedHash()->getHex());

        // apply block effects on db
        $processor->applyBlock($block1aHash);

        // check transaction: status now CONFIRMED
        $tx = $this->loadTransaction(10001, $cbTx1->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $tx->getStatus());

        // check utxos: only one in database, assigned to wallet
        $this->assertEquals(1, $this->getUtxoCount());

        $utxos = $this->sessionDb->getUnspentWalletUtxos($walletId);
        $this->assertCount(1, $utxos);
        $this->assertEquals($walletId, $utxos[0]->getWalletId());
        $this->assertEquals($cbTx1->getTxId()->getHex(), $utxos[0]->getOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $utxos[0]->getOutPoint()->getVout());
        $this->assertEquals(5000000000, $utxos[0]->getValue());
        $this->assertEquals(5000000000, $utxos[0]->getTxOut()->getValue());
        $this->assertEquals($cbScript->getHex(), $utxos[0]->getTxOut()->getScript()->getHex());
        $this->assertNull($utxos[0]->getSpendOutPoint());

        // Prepare block 2a
        $privKeyFactory = new PrivateKeyFactory();
        $rand = new Random();
        $cbPrivKey2 = $privKeyFactory->generateCompressed($rand);
        $cbScript2 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2->getPubKeyHash());

        // create wallet tx spending our utxo: 50BTC -> 1BTC to other user, 48.9 BTC back
        $walletAddress2 = $wallet->getScriptGenerator()->generate();
        $amountSent = 100000000;
        $fee = 10000000;
        $amountChange = $cbTx1->getOutput(0)->getValue() - $amountSent - $fee;
        $this->assertEquals(4890000000, $amountChange);
        $spendBlock1CB = (new TxBuilder())
            ->spendOutputFrom($cbTx1, 0)
            ->output($amountSent, $cbScript2)
            ->output($amountChange, $walletAddress2->getScriptPubKey())
            ->get()
        ;

        $block2a = BlockMaker::makeBlock($this->sessionChainParams, $block1a->getHeader(), $cbScript2, $spendBlock1CB);
        $block2aHash = $block2a->getHeader()->getHash();
        $header2a = null;

        // accept & saveblock
        $this->assertTrue($chain->acceptBlock($this->sessionDb, $block2aHash, $block2a));
        $processor->saveBlock(2, $block2aHash, $block2a);

        // check: now two transaction in wallet
        $this->assertEquals(2, $this->getTransactionCount());
        $this->assertEquals(2, $this->getWalletTransactionCount(10001));
        $this->assertEquals(1, $this->getUtxoCount());

        // check utxos: only 1 for tx1, unspent until 'applyBlock'
        $utxos = $this->sessionDb->getUnspentWalletUtxos($walletId);
        $this->assertCount(1, $utxos);
        $this->assertNull($utxos[0]->getSpendOutPoint());

        // check transaction2: rejected, details otherwise correct
        $tx2 = $this->loadTransaction($walletId, $spendBlock1CB->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_REJECT, $tx2->getStatus());
        $this->assertEquals((-($cbTx1->getValueOut())+$amountChange), $tx2->getValueChange());
        $this->assertEquals($walletId, $tx2->getWalletId());
        $this->assertEquals(2, $tx2->getConfirmedHeight());
        $this->assertEquals($block2aHash->getHex(), $tx2->getConfirmedHash()->getHex());

        // apply block effects on db
        $processor->applyBlock($block2aHash);

        // check transaction1: status unchanged (confirmed)
        $tx = $this->loadTransaction($walletId, $cbTx1->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $tx->getStatus());

        // check utxos: only unspent output in db, the new one
        $this->assertEquals(1, $this->getUtxoCount());
        $utxos = $this->sessionDb->getUnspentWalletUtxos($walletId);
        $this->assertCount(1, $utxos);
        $this->assertEquals($walletId, $utxos[0]->getWalletId());
        $this->assertEquals($spendBlock1CB->getTxId()->getHex(), $utxos[0]->getOutPoint()->getTxId()->getHex());
        $this->assertEquals(1, $utxos[0]->getOutPoint()->getVout());
        $this->assertEquals($amountChange, $utxos[0]->getValue());
        $this->assertEquals($amountChange, $utxos[0]->getTxOut()->getValue());
        $this->assertEquals($walletAddress2->getScriptPubKey()->getHex(), $utxos[0]->getTxOut()->getScript()->getHex());
        $this->assertNull($utxos[0]->getSpendOutPoint());

        // check first utxo has correctly updated spentTxid/spentVout
        $spentUtxo = $this->loadRawUtxo($walletId, $spendBlock1CB->getInput(0)->getOutPoint());
        $this->assertNotNull($spentUtxo->getSpendOutPoint());
        $this->assertEquals($spendBlock1CB->getTxId()->getHex(), $spentUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $spentUtxo->getSpendOutPoint()->getVout());
    }

    /**
     * block 1 coinbase pays into our wallet
     * block 2a contains a spend of block1a coinbase
     * block 2b contains no spends
     * block 3b contains spendTx
     * @throws \BitWasp\Bitcoin\Exceptions\InvalidHashLengthException
     * @throws \BitWasp\Bitcoin\Exceptions\RandomBytesFailure
     */
    public function testProcessPaymentReorg()
    {
        $lines = explode("\n", file_get_contents(__DIR__ . "/sql/test_wallet.sql"));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line != "") {
                $this->sessionDb->getPdo()->exec($line) or die("sorry, query failed: $line");
            }
        }

        $walletScript1 = ScriptFactory::fromHex("76a9145947fbf644461dd030a795469721042a96a572aa88ac");

        $ec = Bitcoin::getEcAdapter();
        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ec)), $ec);
        $wallet = $walletFactory->loadWallet("bip44");
        $walletId = $wallet->getDbWallet()->getId();
        $processor = new BlockProcessor($this->sessionDb, $wallet);

        // first from wallet
        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();

        // Add block 1 - pays to our wallet

        $block1 = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $walletScript1);
        $block1Hash = $block1->getHeader()->getHash();
        $cbTx1 = $block1->getTransaction(0);
        $header1 = null;

        // accept & saveblock
        $this->assertTrue($chain->acceptBlock($this->sessionDb, $block1Hash, $block1, $header1));
        $processor->saveBlock(1, $block1Hash, $block1);
        $chain->updateChain($this->sessionDb, $processor, $header1);

        // check transaction: status now CONFIRMED
        $tx = $this->loadTransaction($walletId, $cbTx1->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $tx->getStatus());

        // tx gets spent in block 2a
        $spendTx = new Transaction(
            1,
            [new TransactionInput($cbTx1->makeOutPoint(0), new Script())],
            [new TransactionOutput(5000000000, new Script(new Buffer("na")))]
        );

        $rand = new Random();
        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey2a = $privKeyFactory->generateCompressed($rand);
        $cbScript2a = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2a->getPubKeyHash());

        $block2a = BlockMaker::makeBlock($this->sessionChainParams, $block1->getHeader(), $cbScript2a, $spendTx);
        $block2aHash = $block2a->getHeader()->getHash();
        $header2a = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block2aHash, $block2a, $header2a));
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());

        // check: spend of tx has been applied
        $this->assertEquals(2, $this->getWalletTransactionCount($walletId));

        $dbSpend = $this->loadTransaction($walletId, $spendTx->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $dbSpend->getStatus());

        // check: original utxos spent
        $spentUtxo = $this->loadRawUtxo($walletId, $spendTx->getInput(0)->getOutPoint());
        $this->assertNotNull($spentUtxo->getSpendOutPoint());
        $this->assertEquals($spendTx->getTxId()->getHex(), $spentUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $spentUtxo->getSpendOutPoint()->getVout());

        // block 2b is created, no tx
        $cbPrivKey2b = $privKeyFactory->generateCompressed($rand);
        $cbScript2b = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2b->getPubKeyHash());

        $block2b = BlockMaker::makeBlock($this->sessionChainParams, $block1->getHeader(), $cbScript2b);
        $block2bHash = $block2b->getHeader()->getHash();
        $header2b = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block2bHash, $block2b, $header2b));
        $this->assertEquals($block2aHash->getHex(), $chain->getBestBlock()->getHash()->getHex());

        // block 3b is created, no tx, updates best block
        $cbPrivKey3b = $privKeyFactory->generateCompressed($rand);
        $cbScript3b = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey3b->getPubKeyHash());

        $block3b = BlockMaker::makeBlock($this->sessionChainParams, $block2b->getHeader(), $cbScript3b);
        $block3bHash = $block3b->getHeader()->getHash();
        $header3b = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block3bHash, $block3b, $header3b));
        $this->assertEquals($block3bHash->getHex(), $chain->getBestBlock()->getHash()->getHex());

        // only one tx which is NOT rejected
        $query = $this->sessionDb->getTransactions($walletId);
        $txs = [];
        while (($tx = $query->fetchObject(DbWalletTx::class))) {
            $txs[] = $tx;
        }
        $this->assertCount(1, $txs);

        // two txs including rejected - bingo
        $query = $this->sessionDb->getTransactions($walletId, true);
        $txs = [];
        while (($tx = $query->fetchObject(DbWalletTx::class))) {
            $txs[] = $tx;
        }
        $this->assertCount(2, $txs);

        // check: original utxos marked unspent
        $spentUtxo = $this->loadRawUtxo($walletId, $spendTx->getInput(0)->getOutPoint());
        $this->assertNull($spentUtxo->getSpendOutPoint());
    }
}