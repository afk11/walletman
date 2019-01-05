walletman
===========

configuration:
 - defaults to $HOME/.walletman, can be set during console command with --datadir=$HOME/anotherdir

## setup

### dependencies

    composer install

#### initializing an old wallet?

#### create a new wallet

 * `bin/wallet db:init`
 * `bin/wallet wallet:sync --ip=<ip>`
 * wait for initial headers sync and for one block
 * `bin/wallet wallet:create --bip44 --bip39-custommnemonic <walletidentifier>`
