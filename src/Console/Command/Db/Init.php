<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Db;

 use BitWasp\Wallet\Console\Command\Command;
 use BitWasp\Wallet\DB\Initializer;
 use Symfony\Component\Console\Input\InputArgument;
 use Symfony\Component\Console\Input\InputInterface;
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

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $this->getStringArgument($input, "database");

        $initializer = new Initializer();
        $initializer->setup($path);

        $output->write("<info>Database setup in location: {$path}</info>\n");
    }
}
