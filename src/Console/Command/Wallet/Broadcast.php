<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console\Command\Wallet;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Network\NetworkFactory;
use BitWasp\Bitcoin\Transaction\TransactionFactory;
use BitWasp\Wallet\Config;
use BitWasp\Wallet\Console\Command\Command;
use BitWasp\Wallet\NetworkInfo;
use BitWasp\Wallet\P2pBroadcast;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Broadcast extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('wallet:broadcast')

            // the short description shown while running "php bin/console list"
            ->setDescription('Broadcast a raw transaction')

            // mandatory arguments
            ->addArgument('ip', InputArgument::REQUIRED, "Remote node ip")
            ->addArgument('rawtx', InputArgument::REQUIRED, "Transaction hex")
            ->addOption('testnet', null, InputOption::VALUE_NONE, "Use testnet network")

            ->setHelp('This command will connect to the specified node and broadcast a raw transaction');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ip = $input->getArgument('ip');
        if ($input->getOption('testnet')) {
            $net = NetworkFactory::bitcoinTestnet();
            $port = 18333;
        } else {
            $net = NetworkFactory::bitcoin();
            $port = 8333;
        }

        try {
            $preparedTx = TransactionFactory::fromHex($input->getArgument('rawtx'));
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to parse transaction: {$e->getMessage()}");
        }

        $loop = \React\EventLoop\Factory::create();
        $broadcaster = new P2pBroadcast($net, $ip, $port);
        $broadcaster->broadcast($loop, $preparedTx);
        $loop->run();
        return 0;
    }
}
