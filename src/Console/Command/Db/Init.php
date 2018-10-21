<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Db;

use BitWasp\Bitcoin\Chain\Params;
use BitWasp\Bitcoin\Math\Math;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\DB\Initializer;
use BitWasp\Wallet\Params\RegtestParams;
use BitWasp\Wallet\Params\TestnetParams;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Init extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('db:init')

            // the short description shown while running "php bin/console list"
            ->setDescription('Initialize a wallet database')

            // An identifier is required for this wallet
            ->addArgument("database", InputArgument::REQUIRED, "Name of database")

            // optionally use regtest mode
            ->addOption('regtest', 'r', InputOption::VALUE_NONE, "Initialize wallet for regtest network")
            ->addOption('testnet', 't', InputOption::VALUE_NONE, "Initialize wallet for testnet network")

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fIsRegtest = $input->getOption('regtest');
        $fIsTestnet = $input->getOption('testnet');
        $path = $this->getStringArgument($input, "database");

        if ($fIsRegtest) {
            $params = new RegtestParams(new Math());
        } else if ($fIsTestnet) {
            $params = new TestnetParams(new Math());
        } else {
            $params = new Params(new Math());
        }

        $initializer = new Initializer();
        $initializer->setup($path, $params);

        $output->write("<info>Database setup in location: {$path}</info>\n");
    }
}
