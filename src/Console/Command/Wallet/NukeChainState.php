<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Chain\ProofOfWork;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Wallet\Chain;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\DBDecorator;
use BitWasp\Wallet\DbManager;
use BitWasp\Wallet\NetworkInfo;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NukeChainState extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:nuke-chainstate')

            // the short description shown while running "php bin/console list"
            ->setDescription('Deletes downloaded chain state, leaving wallet data intact.')

            ->addOption("nuke-headers", null, InputOption::VALUE_NONE, 'Delete headers')
            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')
            ->addOption('debug-db', null, InputOption::VALUE_NONE, "Debug the database usage by printing function calls")

            ->setHelp('This command will unset the BLOCK_VALID flag for all headers, and clears wallet transactions, utxos, and balances.');
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
        $fDebugDb = $input->getOption('debug-db');
        $fDeleteHeaders = $input->getOption('nuke-headers');
        $dataDir = $this->loadDataDir($input);

        $dbMgr = new DbManager();
        $config = Config::fromDataDir($dataDir);
        $db = $dbMgr->loadDb($config->getDbPath($dataDir));
        if ($fDebugDb) {
            $db = new DBDecorator($db);
        }

        $db->getPdo()->beginTransaction();
        try {
            if ($fDeleteHeaders) {
                $db->deleteBlocksFromIndex();
            } else {
                $db->deleteBlockIndex();
            }

            $db->deleteWalletTxs();
            $db->deleteWalletUtxos();
        } catch (\Exception $e) {
            $db->getPdo()->rollBack();
            throw $e;
        }
    }
}
