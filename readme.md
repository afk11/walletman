walletman
===========


configuration:
 - user ui preferences (satoshis, BTC)


## setup

### dependencies

    composer install

#### initializing an old wallet?

#### create a new wallet

 * `wallet db:init <sqlitedb>`
 * `wallet wallet:sync <sqlitedb>`
 * wait for initial headers sync and for one block
