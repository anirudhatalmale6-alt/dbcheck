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

## v2

v2 no longer guesses config file names. It walks the site folder (up to three
levels, skipping asset directories), finds whichever file actually opens the
database connection, and follows one level of `include`/`require` to wherever the
settings really live. It also reads credentials hard-coded directly into a
`mysql_connect()` / `mysqli_connect()` call, and picks up the database name from a
separate `select_db()` call.

Opening the page without the key now prints the correct full URL instead of a bare
"forbidden".
