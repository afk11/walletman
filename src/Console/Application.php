<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console;

class Application extends \Symfony\Component\Console\Application
{
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new Command\Db\Init();
        $commands[] = new Command\Benchmark\MemoryAllHeaders();
        $commands[] = new Command\Wallet\Create();
        $commands[] = new Command\Wallet\NukeChainState();
        $commands[] = new Command\Wallet\SyncWallet();
        $commands[] = new Command\Wallet\ListWallets();
        $commands[] = new Command\Wallet\GetBalance();
        $commands[] = new Command\Wallet\GetNewAddress();
        $commands[] = new Command\Wallet\GetXpub();
        $commands[] = new Command\Wallet\ListTransactions();
        $commands[] = new Command\Wallet\Send();
        $commands[] = new Command\Wallet\SendAll();
        return $commands;
    }
}
