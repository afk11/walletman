What are our block statuses right now? Where are they used?



What are our transaction statuses right now? Where are they used?

need to separate saveBlock from applyBlock to master reorgs

we save txs in tx (walletId,tx)
might be some weird ass stuff that requires we track (blockHash,txHash) separately
rawTx table tracks txid,tx, but uses unique index. weirdness incoming.

currently we don't roll back.

finally added map of [height => block hash] 
