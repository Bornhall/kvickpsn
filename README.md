# KvickPSN

A simple class for subscribing to PSN threads using a logged in PSN account.

Needs a valid PSN id (i.e. the alphanumeric PSN account *name*, up to 16 characters in length) and the corresponding PSN account id (numerical) in kvickpsn.php, and for the first log in you will need to supply the `authenticate()` method with a valid npsss code. Subsequent logins will not need to `authenticate()` unless the refresh token expires, and only then do you need to supply a new npsso code to `authenticate()`.

On first run of the example "kvicktest.php", the class will create a "kvickpsn.json" file containing information on the npsso code, refresh and access tokens and their respective expiration times.

Also, the class will maintain a "kvickpsn-threads.json" file to keep track of when specific threads were last updated. On the first run it will iterate through them and depending on how many threads the logged in PSN account is "subscribed" to, it may take some time to process.
