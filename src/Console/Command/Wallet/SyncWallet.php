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
use React\EventLoop\StreamSelectLoop;
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

            // general options
            ->addOption('mempool', 'm', InputOption::VALUE_NONE, "Synchronize the mempool")
            ->addOption('stopatheight', null, InputOption::VALUE_REQUIRED, "Stop the node after the specified height is reached")

            // debug options
            ->addOption('daemon', null, InputOption::VALUE_NONE, "Start the node in daemon mode")
            ->addOption('debug-db', null, InputOption::VALUE_NONE, "Debug the database usage by printing function calls")
            ->addOption('debug-perblock', null, InputOption::VALUE_NONE, "Debug time taken for all stages while processing all blocks")
            ->addOption('debug-blockwindow', null, InputOption::VALUE_REQUIRED, "Number of blocks to wait before printing debug info", '64')

            ->addOption('blockstats', null, InputOption::VALUE_NONE, "Log block stats to file")

            ->setHelp('This command starts synchronizing the blockchain. An IP address must be provided for the host');
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
        $fDaemon = $input->getOption('daemon');
        $fDebugPerBlock = $input->getOption('debug-perblock');
        $fBlockStatsToFile = $input->getOption('blockstats');
        $stopAtHeight = (int) $input->getOption('stopatheight');

        $fBlockWindow = $this->parseBlockWindow($input);
        $ip = $this->getStringOption($input, 'ip');

        $ecAdapter = Bitcoin::getEcAdapter();
        $random = new Random();
        $loop = new StreamSelectLoop();

        $dbMgr = new DbManager();
        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));
        if ($fDebugDb) {
            $db = new DBDecorator($db);
        }

        $math = new Math();
        $networkInfo = new NetworkInfo();
        $net = $networkInfo->getNetwork($config->getNetwork());
        $port = $networkInfo->getP2pPort($config->getNetwork());
        $params = $networkInfo->getParams($config->getNetwork(), $math);
        $registry = $networkInfo->getSlip132Registry($config->getNetwork());

        $slip132 = new Slip132(new KeyToScriptHelper($ecAdapter));
        $hdSerializer = new Base58ExtendedKeySerializer(new ExtendedKeySerializer($ecAdapter, new GlobalPrefixConfig([
            new NetworkConfig($net, [
                $slip132->p2pkh($registry),
                $slip132->p2wpkh($registry),
                $slip132->p2shP2wpkh($registry),
            ])
        ])));

        $logPath = $config->getLogPath($dataDir);
        $logHandle = fopen($logPath, "a");
        if (!$logHandle) {
            throw new \RuntimeException("Failed to open log file");
        }

        $logger = new Logger('walletman');
        $logger->pushHandler(new \Monolog\Handler\StreamHandler($logHandle, Logger::DEBUG));
        if (!$fDaemon) {
            $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());
        }

        $pow = new ProofOfWork($math, $params);
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
        if ($stopAtHeight) {
            $daemon->setStopAtHeight($stopAtHeight);
        }
        $daemon->init($hdSerializer);

        $loop->addSignal(SIGINT, $closer = function () use ($daemon, $loop, &$closer) {
            $daemon->close($loop);
            $loop->removeSignal(SIGINT, $closer);
        });

        $daemon->sync($loop)
            ->then(null, function (\Exception $e) use ($logger) {
                $logger->error("Caught exception: {$e->getMessage()}\n");
                $logger->error($e->getTraceAsString());
                echo $e->getMessage().PHP_EOL;
                echo $e->getTraceAsString().PHP_EOL;
            });

        if ($fDaemon || $config->isDaemon()) {
            $child_pid = pcntl_fork();
            if ($child_pid) {
                // Exit from the parent process that is bound to the console
                exit();
            }
            // Make the child as the main process.
            posix_setsid();
        }
        $loop->run();
    }
}
