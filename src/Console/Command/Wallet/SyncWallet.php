<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Network\Slip132\BitcoinRegistry;
use BitWasp\Bitcoin\Network\Slip132\BitcoinTestnetRegistry;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\DBDecorator;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\P2pSyncDaemon;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncWallet extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:sync')

            // the short description shown while running "php bin/console list"
            ->setDescription('Synchronize the wallet against a trusted node')

            // mandatory arguments
            ->addArgument('database', InputArgument::REQUIRED, "Database for wallet services")

            ->addOption('ip', null, InputOption::VALUE_REQUIRED, "Provide a trusted nodes hostname", "127.0.0.1")
            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Start wallet in regtest mode")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Start wallet in testnet mode")

            // sync options
            ->addOption('mempool', 'm', InputOption::VALUE_NONE, "Synchronize the mempool")
            ->addOption('debug-db', null, InputOption::VALUE_NONE, "Debug the database usage by printing function calls")
            ->addOption('blockstats', null, InputOption::VALUE_NONE, "Log block stats to file")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command starts the wallet. Some configuration parameters can be provided as options, overriding default or configuration file values');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $fSyncMempool = $input->getOption('mempool');
        $fDebugDb = $input->getOption('debug-db');
        $fBlockStatsToFile = $input->getOption('blockstats');
        $ip = $this->getStringOption($input, 'ip');
        $path = $this->getStringArgument($input, "database");

        $dbMgr = new DbManager();
        $db = $dbMgr->loadDb($path);
        if ($fDebugDb) {
            $db = new DBDecorator($db);
        }

        $ecAdapter = Bitcoin::getEcAdapter();
        $random = new Random();
        $loop = \React\EventLoop\Factory::create();
        if ($fIsRegtest) {
            $port = 18444;
            $params = new RegtestParams(new Math());
            $net = NetworkFactory::bitcoinRegtest();
            $registry = new BitcoinTestnetRegistry();
        } else if ($fIsTestnet) {
            $port = 18333;
            $params = new TestnetParams(new Math());
            $net = NetworkFactory::bitcoinTestnet();
            $registry = new BitcoinTestnetRegistry();
        } else {
            $port = 8333;
            $params = new Params(new Math());
            $net = NetworkFactory::bitcoin();
            $registry = new BitcoinRegistry();
        }

        $slip132 = new Slip132(new KeyToScriptHelper($ecAdapter));
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ])));

        $logger = new Logger('walletman');
        $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

        $pow = new ProofOfWork(new Math(), $params);
        $chain = new Chain($pow);
        $daemon = new P2pSyncDaemon($logger, $ip, $port, $ecAdapter, $net, $params, $db, $random, $chain);
        $daemon->syncMempool($fSyncMempool);
        if ($fBlockStatsToFile) {
            $daemon->produceBlockStatsCsv(__DIR__ . "/../../../../blockstats");
        }
        $daemon->init($hdSerializer);
        $daemon->sync($loop);
        $loop->run();
    }
}
