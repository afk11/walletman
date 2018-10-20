<?php

declare(strict_types=1);

namespace BitWasp\Wallet\Console;

class Application extends \Symfony\Component\Console\Application
{
    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();
        $commands[] = new Command\Db\Init();
        $commands[] = new Command\Wallet\SetupWallet();
        $commands[] = new Command\Wallet\SyncWallet();
        $commands[] = new Command\Wallet\ListWallets();
        $commands[] = new Command\Wallet\GetBalance();
        $commands[] = new Command\Wallet\GetNewAddress();
        return $commands;
    }
}
