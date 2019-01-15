<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig;
use BitWasp\Bitcoin\Key\Deterministic\HdPrefix\NetworkConfig;
use BitWasp\Bitcoin\Key\Deterministic\Slip132\Slip132;
use BitWasp\Bitcoin\Key\KeyToScript\KeyToScriptHelper;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\Base58ExtendedKeySerializer;
use BitWasp\Bitcoin\Serializer\Key\HierarchicalKey\ExtendedKeySerializer;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\DBDecorator;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\P2pSyncDaemon;
use Monolog\Logger;
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

            ->addOption('ip', null, InputOption::VALUE_REQUIRED, "Provide a trusted nodes hostname", "127.0.0.1")

            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            ->addOption('debug-blockwindow', null, InputOption::VALUE_REQUIRED, "Number of blocks to wait before printing debug info", '64')

            // sync options
            ->addOption('mempool', 'm', InputOption::VALUE_NONE, "Synchronize the mempool")
            ->addOption('debug-db', null, InputOption::VALUE_NONE, "Debug the database usage by printing function calls")
            ->addOption('debug-perblock', null, InputOption::VALUE_NONE, "Debug time taken for all stages while processing all blocks")
            ->addOption('blockstats', null, InputOption::VALUE_NONE, "Log block stats to file")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command starts the wallet. Some configuration parameters can be provided as options, overriding default or configuration file values');
    }

    protected function parseBlockWindow(InputInterface $input): int
    {
        $indexValue = $input->getOption("debug-blockwindow");
        if (!\is_string($indexValue)) {
            throw new \RuntimeException("Invalid value provided for debug-blockwindow");
        }
        if ($indexValue != (string)(int)$indexValue) {
            throw new \RuntimeException("invalid value for debug-blockwindow");
        }
        return (int) $indexValue;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fSyncMempool = (bool) $input->getOption('mempool');
        $dataDir = $this->loadDataDir($input);
        $fDebugDb = $input->getOption('debug-db');
        $fDebugPerBlock = $input->getOption('debug-perblock');
        $fBlockStatsToFile = $input->getOption('blockstats');
        $fBlockWindow = $this->parseBlockWindow($input);
        $ip = $this->getStringOption($input, 'ip');

        $ecAdapter = Bitcoin::getEcAdapter();
        $random = new Random();
        $loop = \React\EventLoop\Factory::create();

        $dbMgr = new DbManager();
        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));
        if ($fDebugDb) {
            $db = new DBDecorator($db);
        }

        $networkInfo = new NetworkInfo();
        $net = $networkInfo->getNetwork($config->getNetwork());
        $port = $networkInfo->getP2pPort($config->getNetwork());
        $params = $networkInfo->getParams($config->getNetwork(), new Math());
        $registry = $networkInfo->getSlip132Registry($config->getNetwork());

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
        if ($fDebugPerBlock) {
            $daemon->setPerBlockDebug(true);
        }
        if ($fBlockWindow) {
            $daemon->setBlockStatsWindow($fBlockWindow);
        }
        if ($fBlockStatsToFile) {
            $daemon->produceBlockStatsCsv(__DIR__ . "/../../../../blockstats");
        }
        $daemon->init($hdSerializer);
        $daemon->sync($loop)
            ->then(null, function (\Exception $e) {
                echo "error received all the way back here\n";
            });
        $loop->run();
    }
}
