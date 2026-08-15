# dbcheck

A single read-only PHP diagnostic for legacy PHP applications that fail with
"Could not connect" or a blank HTTP 500.

## Use

1. Upload `dbcheck.php` into the folder of the site you are testing.
2. Open `https://<the-site>/dbcheck.php?k=oester-check-2026`
3. Read the output, then **delete the file from the server**.

## What it reports

- the PHP version actually executing (not what the control panel claims)
- whether the `mysql`, `mysqli` and `pdo_mysql` extensions exist
- which config file it found, and the database host / user / database name in it
- the exact error MySQL returns, for each of mysqli and mysql, against the
  configured host plus `localhost` and `127.0.0.1`

It changes nothing. Passwords are never printed - only their length.
