<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Db;

 use BitWasp\Bitcoin\Chain\ProofOfWork;
 use BitWasp\Bitcoin\Math\Math;
 use BitWasp\Wallet\Chain;
 use BitWasp\Wallet\Console\Command\Command;
 use BitWasp\Wallet\DB\Initializer;
 use BitWasp\Wallet\NetworkInfo;
 use BitWasp\Wallet\NetworkName;
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
            ->setDescription('Initialize a data directory')

            // Network selection options
            ->addOption("regtest", "r", InputOption::VALUE_NONE, "Initialize database for regtest chain")
            ->addOption("testnet", "t", InputOption::VALUE_NONE, "Initialize database for testnet chain")

            // Data directory - defaults to
            ->addOption("datadir", "d", InputOption::VALUE_REQUIRED, 'Data directory, defaults to $HOME/.walletman')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('Initializes a data directory for the wallet. The directory must not exist when calling this command.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fTestnet = $input->getOption('testnet');
        $fRegtest = $input->getOption('regtest');
        $path = $this->loadDataDir($input);
        $math = new Math();
        if ($fTestnet && $fRegtest) {
            throw new \RuntimeException("Cannot set both regtest and testnet flags");
        } else if ($fRegtest) {
            $networkName = NetworkName::BITCOIN_REGTEST;
        } else if ($fTestnet) {
            $networkName = NetworkName::BITCOIN_TESTNET3;
        } else {
            $networkName = NetworkName::BITCOIN;
        }

        $networkInfo = new NetworkInfo();
        $params = $networkInfo->getParams($networkName, $math);


        if (\file_exists($path)) {
            throw new \RuntimeException("datadir exists, delete and try again: $path");
        }
        if (!\mkdir($path)) {
            throw new \RuntimeException("unable to create datadir: $path");
        }

        $initializer = new Initializer();
        $initializer->setupConfig($path, $networkName);
        $db = $initializer->setupDb($path);

        $chain = new Chain(new ProofOfWork($math, $params));
        $chain->init($db, $params);

        $output->write("<info>Initialized {$networkName} database: {$path}</info>\n");
    }
}
