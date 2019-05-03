<?php

declare(strict_types=1);

namespace BitWasp\Test\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\Slip132\BitcoinTestnetRegistry;
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

    private function getWalletTransactionCount(int $walletId, bool $includeRejected): int
    {
        $sql = "select count(*) from tx where walletId = ? " .
            ($includeRejected ? "" : " AND status != -1 ") .
            "order by id asc";

        $query = $this->sessionDb->getPdo()->query($sql);
        if (!$query->execute([$walletId])) {
            throw new \RuntimeException("failed to execute query");
        }
        $obj = $query->fetchColumn(0);
        return (int) $obj;
    }

    private function loadRawUtxo(int $walletId, OutPointInterface $outPoint): ?DbUtxo
    {
        $query = $this->sessionDb->getPdo()->query("select * from utxo where walletId = ? and txid = ? AND vout = ?");
        if (!$query->execute([$walletId, $outPoint->getTxId()->getHex(), $outPoint->getVout()])) {
            throw new \RuntimeException("cannot load expected utxo {$outPoint->getTxId()->getHex()} {$outPoint->getVout()}");
        }
        $obj = $query->fetchObject(DbUtxo::class);
        if (!$obj) {
            return null;
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
        $this->assertEquals(1, $this->getWalletTransactionCount(10001, true));
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
        $this->assertEquals(2, $this->getWalletTransactionCount(10001, true));
        $this->assertEquals(1, $this->getUtxoCount());

        // check utxos: only 1 for tx1, unspent until 'applyBlock'
        $utxos = $this->sessionDb->getUnspentWalletUtxos($walletId);
        $this->assertCount(1, $utxos);
        $this->assertNull($utxos[0]->getSpendOutPoint());

        // check transaction2: rejected, details otherwise correct
        $tx2 = $this->loadTransaction($walletId, $spendBlock1CB->getTxId());
        $this->assertFalse($tx2->isCoinbase());
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

    public function testTestReorgWalletCoinbaseTx()
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

        // from wallet
        $walletScript0 = ScriptFactory::fromHex("76a9145947fbf644461dd030a795469721042a96a572aa88ac");

        $privKeyFactory = new PrivateKeyFactory();
        $rand = new Random();
        $cbPrivKey2 = $privKeyFactory->generateCompressed($rand);
        $cbScript2 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey2->getPubKeyHash());
        $cbPrivKey3 = $privKeyFactory->generateCompressed($rand);
        $cbScript3 = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey3->getPubKeyHash());

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();

        // Add block 1a - single payment to wallet in coinbase
        $block1a = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $walletScript0);
        $block1aHash = $block1a->getHeader()->getHash();
        $header1a = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block1aHash, $block1a));

        // check: one wallet received 1 transaction
        $this->assertEquals(1, $this->getTransactionCount());
        $this->assertEquals(1, $this->getWalletTransactionCount(10001, false));
        $this->assertEquals(1, $this->getUtxoCount());

        $txs = $this->sessionDb->getTransactions($walletId);
        $tx = $txs->fetchObject(DbWalletTx::class);
        $this->assertTrue($tx->isCoinbase());

        // Add block 1b - pays someone else
        $block1b = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $cbScript2);
        $block1bHash = $block1b->getHeader()->getHash();
        $header1a = null;

        // accept & saveblock
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block1bHash, $block1b));

        // Add block 2b - reorg, no more cb 1a
        $block1c = BlockMaker::makeBlock($this->sessionChainParams, $block1b->getHeader(), $cbScript3);
        $block1cHash = $block1c->getHeader()->getHash();
        $header1a = null;

        // accept & saveblock
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block1cHash, $block1c));

        // check: one wallet received 1 transaction, despite two being in the block:
        $this->assertEquals(0, $this->getWalletTransactionCount(10001, false));
        $this->assertEquals(0, $this->getUtxoCount());
    }

    /**
     * block 1 coinbase pays into our wallet
     * block 2a contains a spend of block1a coinbase
     * block 2b contains no spends
     * block 3b contains no spends
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
        $this->assertTrue($tx->isCoinbase());

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
        $this->assertEquals(2, $this->getWalletTransactionCount($walletId, true));

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

        // check: wallet only has 1 valid tx
        $this->assertEquals(1, $this->getWalletTransactionCount($walletId, false));
        $dbFund = $this->loadTransaction($walletId, $cbTx1->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $dbFund->getStatus());

        // check: spendTx status is now rejected, and is included when we request rejected txs in tx count
        $this->assertEquals(2, $this->getWalletTransactionCount($walletId, true));
        $dbSpend = $this->loadTransaction($walletId, $spendTx->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_REJECT, $dbSpend->getStatus());

        // check: original utxos marked unspent
        $spentUtxo = $this->loadRawUtxo($walletId, $spendTx->getInput(0)->getOutPoint());
        $this->assertNull($spentUtxo->getSpendOutPoint());

        // block 4b is created, includes spendTx again
        $cbPrivKey4b = $privKeyFactory->generateCompressed($rand);
        $cbScript4b = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey4b->getPubKeyHash());

        $block4b = BlockMaker::makeBlock($this->sessionChainParams, $block3b->getHeader(), $cbScript4b, $spendTx);
        $block4bHash = $block4b->getHeader()->getHash();
        $header4b = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block4bHash, $block4b, $header4b));
        $this->assertEquals($block4bHash->getHex(), $chain->getBestBlock()->getHash()->getHex());

        // check tx created again
        $dbSpend = $this->loadTransaction($walletId, $spendTx->getTxId());
        $this->assertEquals(DbWalletTx::STATUS_CONFIRMED, $dbSpend->getStatus());

        // check: original utxos spent by spendTx
        $spentUtxo = $this->loadRawUtxo($walletId, $spendTx->getInput(0)->getOutPoint());
        $this->assertNotNull($spentUtxo->getSpendOutPoint());
        $this->assertEquals($spendTx->getTxId()->getHex(), $spentUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $spentUtxo->getSpendOutPoint()->getVout());
    }

    public function testMultiplePaymentsInBlock()
    {
        $lines = explode("\n", file_get_contents(__DIR__ . "/sql/test_wallet.sql"));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line != "") {
                $this->sessionDb->getPdo()->exec($line) or die("sorry, query failed: $line");
            }
        }

        $privKeyFactory = new PrivateKeyFactory();
        $cbPrivKey = $privKeyFactory->generateCompressed(new Random());
        $cbScript = ScriptFactory::scriptPubKey()->p2pkh($cbPrivKey->getPubKeyHash());

        $walletScript1 = ScriptFactory::fromHex("76a9145947fbf644461dd030a795469721042a96a572aa88ac");
        $walletScript2 = ScriptFactory::fromHex("76a91433496192592d0bcba7b30ac208eeb88f667e8d4388ac");
        $changeScript1 = ScriptFactory::fromHex("76a9143803b3f6910d1155a0907d072c2420e872c60c0688ac");
        $changeScript2 = ScriptFactory::fromHex("76a9140195c36e8d06d56bde6ac2f5415b4fb20cb5e5b488ac");

        $wallet2Script1 = ScriptFactory::fromHex("0014efc22b20c7d51c1549da81b4e86baaf585a47afd");
        $wallet2Script2 = ScriptFactory::fromHex("0014f5eed4c937f5f4039dc740b83f2c3e8bf535eaae");

        $ec = Bitcoin::getEcAdapter();
        $slip132 = new Slip132();
        $testnetRegistry = new BitcoinTestnetRegistry();
        $cfg = new GlobalPrefixConfig([
            new NetworkConfig($this->sessionNetwork, [
                $slip132->p2pkh($testnetRegistry),
                $slip132->p2wpkh($testnetRegistry),
            ])
        ]);

        $walletFactory = new Factory($this->sessionDb, $this->sessionNetwork, new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ec, $cfg)), $ec);
        $wallet1 = $walletFactory->loadWallet("bip44");
        $walletId1 = $wallet1->getDbWallet()->getId();
        $wallet2 = $walletFactory->loadWallet("bip84");
        $walletId2 = $wallet2->getDbWallet()->getId();

        $processor = new BlockProcessor($this->sessionDb, ...[$wallet1, $wallet2]);

        $pow = new ProofOfWork(new Math(), $this->sessionChainParams);
        $chain = new Chain($pow);
        $chain->init($this->sessionDb, $this->sessionChainParams);

        $genesis = $chain->getBestHeader();

        // Add block 1 - pays to our wallet to start test
        $block1 = BlockMaker::makeBlock($this->sessionChainParams, $genesis->getHeader(), $walletScript1);
        $block1Hash = $block1->getHeader()->getHash();
        $cbTx1 = $block1->getTransaction(0);
        $header1 = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block1Hash, $block1, $header1));

        // add block 2 - creates second utxo for test
        $block2 = BlockMaker::makeBlock($this->sessionChainParams, $block1->getHeader(), $walletScript2);
        $block2Hash = $block2->getHeader()->getHash();
        $cbTx2 = $block2->getTransaction(0);
        $txFundAmt = $cbTx1->getValueOut();
        $header2 = null;
        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block2Hash, $block2, $header2));

        // add block 3 - containing lots of spends
        $sendAmount1 = 250000;
        $fee = 250;
        $change1 = $txFundAmt - $sendAmount1 - $fee;

        // sends some out, some to
        $spend1 = (new TxBuilder())
            ->spendOutputFrom($cbTx1, 0)
            ->spendOutputFrom($cbTx2, 0)
            ->output($sendAmount1, $wallet2Script1)
            ->output($change1, $changeScript1)
            ->get()
        ;

        $sendAmount2 = 150000000;
        $fee2 = 350;
        $change2 = $change1 - $sendAmount2 - $fee2;
        $spend2 = (new TxBuilder())
            ->spendOutputFrom($spend1, 1)
            ->output($change2, $changeScript2)
            ->output($sendAmount2, $wallet2Script2)
            ->get();

        $sendAmount3 = 2500000000;
        $fee3 = 290;
        $change3 = $change2 - $sendAmount3 - $fee3;
        $spend3 = (new TxBuilder())
            ->spendOutputFrom($spend2, 0)
            ->output($sendAmount3, $walletScript2)
            ->output($change3, $changeScript2)
            ->get();

        $blockTxs = [$spend1, $spend2, $spend3];
        $block3 = BlockMaker::makeBlock($this->sessionChainParams, $block2->getHeader(), $cbScript, ...$blockTxs);
        $block3Hash = $block3->getHeader()->getHash();
        $header3 = null;

        $this->assertTrue($chain->processNewBlock($this->sessionDb, $processor, $block3Hash, $block3, $header3));
        $this->assertEquals($block3Hash->getHex(), $chain->getBestBlock()->getHash()->getHex());

        $wallet1Txs = [];
        $gettxs = $this->sessionDb->getTransactions($walletId1);
        while ($tx = $gettxs->fetchObject(DbWalletTx::class)) {
            $wallet1Txs[$tx->getTxId()->getHex()] = $tx;
        }

        $wallet2Txs = [];
        $gettxs = $this->sessionDb->getTransactions($walletId2);
        while ($tx = $gettxs->fetchObject(DbWalletTx::class)) {
            $wallet2Txs[$tx->getTxId()->getHex()] = $tx;
        }
        $this->assertArrayHasKey($spend1->getTxId()->getHex(), $wallet1Txs, "should have tx in list 1 {$spend1->getTxId()->getHex()}");
        $this->assertArrayHasKey($spend2->getTxId()->getHex(), $wallet1Txs, "should have tx in list 1 {$spend2->getTxId()->getHex()}");
        $this->assertArrayHasKey($spend3->getTxId()->getHex(), $wallet1Txs, "should have tx in list 1 {$spend3->getTxId()->getHex()}");

        $this->assertArrayHasKey($spend1->getTxId()->getHex(), $wallet2Txs, "should have tx in list 2 {$spend1->getTxId()->getHex()}");
        $this->assertArrayHasKey($spend2->getTxId()->getHex(), $wallet2Txs, "should have tx in list 2 {$spend2->getTxId()->getHex()}");

        // cb1 utxo spent by spend1 idx 0
        $cb1Utxo = $this->loadRawUtxo($walletId1, $cbTx1->makeOutPoint(0));
        $this->assertTrue($cb1Utxo->isSpent());
        $this->assertEquals($spend1->getTxId()->getHex(), $cb1Utxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $cb1Utxo->getSpendOutPoint()->getVout());

        // cb2 utxo spent by spend1 idx 1
        $cb2Utxo = $this->loadRawUtxo($walletId1, $cbTx2->makeOutPoint(0));
        $this->assertTrue($cb2Utxo->isSpent());
        $this->assertEquals($spend1->getTxId()->getHex(), $cb2Utxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(1, $cb2Utxo->getSpendOutPoint()->getVout());

        // spend1 vout 0 went outside our wallet, ignored
        $this->assertNull($this->loadRawUtxo($walletId1, $spend1->makeOutPoint(0)));

        // spend1 vout 1 spent by spend2 idx 1
        $spendUtxo = $this->loadRawUtxo($walletId1, $spend1->makeOutPoint(1));
        $this->assertTrue($spendUtxo->isSpent());
        $this->assertEquals($spend2->getTxId()->getHex(), $spendUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $spendUtxo->getSpendOutPoint()->getVout());

        // spend2 vout 1 went outside our wallet, ignored
        $this->assertNull($this->loadRawUtxo($walletId1, $spend2->makeOutPoint(1)));

        // spend2 vout 0 spent by spend3 idx 0
        $spendUtxo = $this->loadRawUtxo($walletId1, $spend2->makeOutPoint(0));
        $this->assertTrue($spendUtxo->isSpent());
        $this->assertEquals($spend3->getTxId()->getHex(), $spendUtxo->getSpendOutPoint()->getTxId()->getHex());
        $this->assertEquals(0, $spendUtxo->getSpendOutPoint()->getVout());

        // spend3 vout 0 sent to normal address, unspent
        $spendUtxo = $this->loadRawUtxo($walletId1, $spend3->makeOutPoint(0));
        $this->assertFalse($spendUtxo->isSpent());

        // spend3 vout 1 sent to change address, unspent
        $spendUtxo = $this->loadRawUtxo($walletId1, $spend3->makeOutPoint(0));
        $this->assertFalse($spendUtxo->isSpent());
    }
}
