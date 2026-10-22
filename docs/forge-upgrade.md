# Forge upgrade to PHP 8.5

This fork preserves Crater's screens, routes, business data, invoice templates,
existing environment variable names and storage paths. The application updater
has been removed. Deploy future changes from Git through Forge.

## Requirements

- PHP 8.5 recommended. PHP 8.4 is also supported and tested.
- Composer 2.2 or newer, using the site's PHP version.
- PHP extensions: bcmath, ctype, curl, dom, exif, fileinfo, filter, gd, iconv,
  intl, mbstring, openssl, pdo_mysql (or the existing database driver), session,
  tokenizer, xml, xmlwriter and zip. Run `composer check-platform-reqs --no-dev`
  on the target server for the authoritative requirements of the locked packages.
- Retain the existing database server during the PHP cutover. Validate its
  version against Laravel 13's requirements before scheduling the change.
- Backups need a working database dump executable, such as `mysqldump`.
- Production assets are committed under `public/build`. Node is not required
  on Forge. For source builds use Node 22.12+ or Node 24+ and `npm ci && npm run build`.

## Stage the upgrade first

1. Record the live Git commit/branch, PHP version, installed Crater version,
   deployment script, cron commands and queue worker configuration.
2. Inspect `git status` and any changes made directly on the server. Reconcile
   custom templates and other tracked changes before pulling this branch.
3. Back up the database and all persistent files, and verify restoration.
   Preserve `.env`, its original `APP_KEY`, `storage`, `public/media` if used,
   and `Modules` (which is deliberately excluded from Git). Preserve custom
   filesystem roots outside the checkout as well.
4. Restore a copy into a separate Forge staging site with a separate database
   and file roots. Prevent it from sending real emails, processing production
   jobs, or generating scheduled recurring invoices. Use isolated credentials
   for remote storage as well. Do not point staging at production buckets.
5. Install PHP 8.5 and the extensions. Configure both the web site and all
   commands/workers to use it. Keep the old PHP version available for rollback.
6. Check out the upgraded fork and install the committed dependency lock file:

   ```sh
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   composer check-platform-reqs --no-dev
   php artisan migrate --force
   php artisan config:clear
   php artisan view:cache
   ```

7. Confirm admin and customer login, permissions, existing public invoice and
   estimate links, document totals, all PDF templates and stored attachments.
   Send a real test email only to an authorised test destination. Check the
   actual storage providers and installed modules used by the live instance.
   Exercise backup and restore, recurring invoices and queue handling in staging.

### Database migration

The new migration adds nullable `media.generated_conversions` and
`personal_access_tokens.expires_at` columns. Existing media conversion flags are
copied from `custom_properties` without deleting their old values. Existing
bearer tokens keep their no-expiry behaviour. No business records are renumbered
or reseeded. Historical migrations have also been made compatible with modern
SQLite; migrations already recorded in production are not rerun.

The original local storage root remains `storage/app`, including the
`database_created` installation marker. Do not replace it with a new Laravel
application skeleton or move existing files.

### Recovering the customer migration failure

If Forge reports `Carbon::format(): Argument #1 ($format) must be of type string,
null given` in `2021_06_30_062411_update_customer_id_in_all_tables`, deploy the
fixed revision and run the normal `php artisan migrate --force` step again.
Laravel skips the migrations already recorded as successful. This particular
failure happens while serializing the first legacy customer, before its insert
or any destructive schema changes in that migration. Do not reset the database
or roll back the successful migrations to retry it.

The corrected migration copies stored columns directly, preserving password
hashes and original timestamps, and transfers document/address references,
custom fields and media ownership. Date formatting now defaults to Crater's
`Y/m/d` format when company settings or an HTTP company header are absent.
Subsequent historical migrations also preserve each company's numbering
settings and normalize nullable customer portal flags before changing the column.

The automated Upgrade suite builds a populated database at the exact migration
boundary shown in this Forge failure, then runs all outstanding migrations and
checks the stored records on SQLite and MySQL. It covers two companies with
different numbering prefixes and a customer with legacy NULL portal settings.

The npm deprecation, Sass, Tailwind and bundle-size notices in the supplied log
do not cause this deployment failure. The build completes successfully before
the migration fails. The 15 npm advisories affect development/build dependencies;
`npm audit --omit=dev` reports zero runtime findings. Production assets are already
committed, so the documented Forge deployment does not require `npm ci` or a
frontend build on the server.

## Production cutover

Use the existing Forge site. Do not delete and recreate its repository/site.

1. Disable push-to-deploy during preparation. Pause the site's scheduler and
   drain/stop its queue workers so old and new code cannot generate duplicate
   invoices during the switch.
2. Put the existing application into maintenance mode using its old PHP binary,
   then take the final restorable database/file snapshot.
3. Change the existing checkout's origin to `slashequip/crater`, retaining its
   `.env`, storage and ignored modules. Select the reviewed release branch or
   merge the upgrade into the branch Forge already deploys. Authorise the
   existing Forge deploy key for the fork and update Forge's repository/branch
   metadata and deployment hook as appropriate.
4. Select PHP 8.5 for the site. Update explicit PHP paths in the scheduler,
   Supervisor/queue workers and deployment script. All must use the same runtime.
5. Deploy the exact revision tested on staging. The deployment script below is
   for a standard Forge checkout, not a zero-downtime release directory.
6. Check login, a known existing invoice/PDF and logs. Resume workers and the
   scheduler after the checks pass, then re-enable push-to-deploy if wanted.

### Standard Forge deployment script

Use this after the one-time cutover above. Enter maintenance with the old PHP binary before the first switch. The script
recognises the existing maintenance file and skips booting old code on new PHP.
Adapt the working directory and retain any site-specific operations.

```sh
set -euo pipefail
cd "$FORGE_SITE_PATH"

if [ ! -f storage/framework/down ]; then
    "$FORGE_PHP" artisan down --retry=60
fi
git pull --ff-only origin "$FORGE_SITE_BRANCH"
# Clear old cached configuration/package manifests before the new app boots.
rm -f bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
"$FORGE_PHP" artisan migrate --force --no-interaction
"$FORGE_PHP" artisan config:clear
"$FORGE_PHP" artisan view:cache
"$FORGE_PHP" artisan queue:restart
sudo -S service "$FORGE_PHP_FPM" reload
"$FORGE_PHP" artisan up
```

Crater's mail configuration screen writes to `.env`, so the script keeps
configuration uncached to preserve those settings changes.

Forge selects PHP for Composer using the site's PHP setting. If the installation
uses a custom Composer launcher, explicitly invoke its PHAR with `$FORGE_PHP`.
Never run `composer update`, seeders, the installer, or `key:generate` during a
production deployment. The script deliberately leaves maintenance enabled if a
step fails. If your site uses zero-downtime deployments, adapt Forge's existing
release activation script and share the persistent paths listed above.

## Rollback

Keep the old commit, dependency lock file, built assets and PHP runtime ready.
Keep the scheduler/workers paused while restoring them. Clear cached configuration
and package manifests, check out the old revision and reinstall its lock file
with the old PHP binary. Preserve the original `.env` and `APP_KEY`.

The two new nullable columns are compatible with the old application and can
remain in place for a code rollback; do not automatically run `migrate:rollback`
on production. If database/file restoration is needed, restore the matching
snapshot and account for writes made after it. Verify login and an existing
invoice before bringing the site up and resuming background work.

## Validation and remaining environment checks

Validation completed locally:

- 282 tests / 644 assertions passed on PHP 8.4 with SQLite.
- 282 tests / 644 assertions passed on PHP 8.5 with SQLite.
- 282 tests / 644 assertions passed on PHP 8.5 with MySQL 8.0.
- A clean `composer install --no-dev` boots Laravel 13.38 and compiles views.
- A clean `npm ci && npm run build` reproduces the production manifest.
- Composer and production npm dependency audits report no known vulnerabilities.
- Browser verification covered login, the dashboard and creating an invoice with
  customer selection, an item and rich-text notes.

Automated checks cover PHP 8.4/8.5,
legacy schema migration, sessions and tokens, PDFs, mail attachments, local media,
Dropbox adapter construction and actual ZIP backup creation. A synthetic database
created by the original Laravel 8 app was upgraded on both SQLite and MySQL,
and its checked records were compared before/after. A MySQL backup was created
with the upgraded package and restored into a separate database for comparison.

Production's exact version, customisations, paid modules, real email delivery,
remote storage credentials and backup restoration must still be validated on the
staging copy. No production deployment or Forge settings have been changed.

The production PHP and browser dependency audits have no known findings as of
the upgrade. The legacy Tailwind 3 and ESLint build dependency trees still have npm audit
advisories. These tools process repository sources during builds; they are not
served as application code. Upgrading Tailwind across a major version would
change CSS behaviour and is outside this compatibility upgrade. Review the
current `npm audit` output before accepting untrusted build inputs.


## Optional preview infrastructure

The inherited Uffizzi preview workflows are opt-in with the repository variable
`ENABLE_UFFIZZI_PREVIEWS=true`. They do not run for this Forge fork by default.
The PHP images have been updated, but Docker image builds were not executed
locally because Docker is unavailable on this machine.

## Running the tests

```sh
composer install
vendor/bin/pest
```

For MySQL, create a disposable database named `crater_test` first and provide
`DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for that test server:

```sh
CRATER_TEST_MYSQL=1 vendor/bin/pest --configuration phpunit.mysql.xml
```

That configuration deliberately forces the test database name. It truncates
tables between tests and must only be used against a disposable test instance.
