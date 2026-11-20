# Build spec — G9: contract billing for every business (2026-11-04)

PHP 8.1/8.3 on production (keep 7.4-compatible syntax), MySQL 5.7, no autoloader (require_once + `new`). Services
hold logic; every new rule has a unit test (SQLite fixtures where a DB is needed). Mowology's own run must behave
exactly as today.

## Today

`app/Modules/Contracts/Cron/contract_billing.php` runs only in the original business (cPanel: monthly on the 1st at
06:00, plus a daily `retry-only` run). A second business's contracts never bill. It emails invoices (with PDF) and
texts clients. There is no notion of a "test business", so a rehearsal business whose clients came from a QuickBooks
sandbox (real-looking emails) could email strangers once it has a contract.

## 1. Test businesses (new)

- `Tenant::isTest(): bool` — true when the current business's registry entry has `'test' => true`
  (`public/app_config/tenants.php`, `$ITTAI_TENANTS[key]['test']`). Original business: always false. Pure resolver
  testable the same way `Tenant::resolve()` is (add a pure `Tenant::isTestCfg(array $cfg): bool` and a test).
- No other behaviour change in this build except contract billing below.

## 2. Contract billing per business

- `BusinessCronFanout::commands()` / `run()` gain an optional `array $extraArgs = []` appended after
  `--tenant=<key>` (argv array, no shell). Test it.
- At the end of the original business's run (both modes), fan out:
  `BusinessCronFanout::run(__FILE__-equivalent path, 600, null, null, $retryOnly ? ['retry-only'] : [])` and put
  `BusinessCronFanout::summary()` in the original run's summary/log line. Fan-out failures never change the original
  business's exit code or invoices (try/catch).
- The child run (`--tenant=<key>`) must not fan out again (`BusinessCronFanout::run` already returns [] when not the
  default business — keep that).
- Lock: the MySQL `GET_LOCK('mw_contract_billing', 0)` name is server-wide, so a business run would wait on / block
  another business. Make it `'mw_contract_billing_' . Tenant::key()` (the original business keeps a name that still
  blocks two of its own runs). Release behaviour unchanged.
- `recordCronRun('contract_billing', …)` already writes to the current business's database — keep, so each business
  has its own run rows.
- In a TEST business (`Tenant::isTest()`): create the invoices as today (so the walk can see them) but send nothing —
  no email, no SMS; leave each invoice 'draft' with a clear note in the summary ("test business: N invoices made, not
  sent"). Put the decision in one pure, tested function (e.g. `ContractBillingPolicy::sendAllowed(bool $isTest): bool`
  in `app/Modules/Contracts/Services/`), used by both the monthly path and the retry-only path (held invoices are not
  sent in a test business either).

## Tests

- Tenant: isTestCfg true only for `'test' => true`; default business never test.
- BusinessCronFanout: extra args appended after `--tenant=`; none by default (existing tests unchanged).
- ContractBillingPolicy: send allowed / refused.
- A source-scan test that contract_billing.php calls `BusinessCronFanout::run` with its own path and a per-business
  lock name, and consults the policy before every send.
- Full suite: `php -d memory_limit=1G vendor/bin/phpunit`.
