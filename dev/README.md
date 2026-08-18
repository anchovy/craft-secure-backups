# Development

Three layers of verification, in increasing cost and decreasing frequency.

## 1. Static analysis and unit tests

```sh
composer ci        # check-cs + phpstan + test
```

No Craft application, no database, no fixtures. The suite drives the real `openssl`
and `gzip` binaries, because the property worth proving is that a backup written here
can be read anywhere, and mocking the subprocesses would prove nothing about that.

To point the analysis at the other major Craft version:

```sh
composer update --with "craftcms/cms:^4.3.5"
composer update --with "craftcms/cms:^5.0.0" --ignore-platform-req=php
```

`--ignore-platform-req=php` is only needed when local PHP is below 8.2. PHPStan parses
rather than executes, so the analysis stays valid.

The tests are a different matter, because they actually run. On PHP 8.1 that second command
resolves PHPUnit 11, which requires PHP 8.2 and refuses to start, so `composer test` fails for a
reason that has nothing to do with Craft 5. Pin PHPUnit too when exercising the suite against a
Craft 5 install locally:

```sh
composer update --with "craftcms/cms:^5.0.0" --with "phpunit/phpunit:^10.5" --ignore-platform-req=php
```

CI needs none of this: its Craft 5 jobs run on PHP 8.2 and 8.4, where the newest PHPUnit the
constraint allows is also the one that runs.

### Running the suite on a newer PHP than this machine has

Better than either workaround above, and closer to what CI actually does: run it inside a bench
container, which has the PHP version that Craft line requires. Nothing is installed over the
plugin's own `vendor`, because the copy is made outside the bind mount.

```sh
cd ~/ddev/craft-test-environments/v5

ddev exec bash -c '
  rm -rf /tmp/sbtest && mkdir -p /tmp/sbtest
  cd /var/www/plugins/craft-secure-backups
  cp -r src tests composer.json phpunit.xml ecs.php phpstan.neon /tmp/sbtest/
  cd /tmp/sbtest && composer update --no-interaction
'

ddev exec sh -c 'cd /tmp/sbtest && composer ci'
```

On the v5 bench that resolves Craft 5 and PHPUnit 11 on PHP 8.2 with no platform overrides at
all, which is the combination the release is actually supported on. `/tmp/sbtest` is
container-local and disposable; it goes when the container restarts.

Copying rather than running in place matters: the plugin is bind-mounted, so `composer update`
inside `/var/www/plugins/craft-secure-backups` would rewrite the host's `vendor` for the
container's PHP version and leave `composer test` broken on the host.

## 2. CI

`.github/workflows/ci.yml` runs the above across the full support matrix on every push:
Craft 4 and 5, PHP 8.0 through 8.4, and `--prefer-lowest` runs that resolve the declared
floors exactly. That last part is what keeps `craftcms/cms: ^4.3.5` and `php: >=8.0.2`
honest instead of aspirational.

A second job guards packaging: `composer validate --strict`, a check that no
developer-only file leaked past `.gitattributes` into the release archive, and a check
that `CHANGELOG.md` still parses as Craft's updater expects.

## 3. DDEV benches

For what the other two layers cannot reach: the Control Panel backup utility, the
settings screen, and `craft db/backup` / `craft db/restore` against a real database.

```sh
./dev/scaffold-bench.sh 4     # ~/ddev/craft-test-environments/v4, PHP 8.1, Craft 4
./dev/scaffold-bench.sh 5     # ~/ddev/craft-test-environments/v5, PHP 8.2, Craft 5
./dev/scaffold-bench.sh 6     # ~/ddev/craft-test-environments/v6, PHP 8.5, Craft 6 alpha
```

The Craft 6 bench is built **without** the plugin, because the plugin cannot run there: Craft 6
replaced Yii with Laravel, and the entire `craft\` namespace it imports from is gone. An empty
Settings → Plugins screen on that bench is expected. It also needs **DDEV v1.25.0 or newer**,
which is where PHP 8.5 support landed.

Benches live **outside this repository** on purpose. They are shared infrastructure:
one pair of Craft installs can host every plugin you develop, and nesting them inside
one plugin's folder would tie their lifetime to that plugin. Override the location with
`CRAFT_BENCH_ROOT`.

Each bench mounts plugin source into the container at `/var/www/plugins/<name>` and
declares a Composer path repository globbing that directory, with `symlink: true`. Edits
here are live in both benches immediately, with no reinstall step. The mount is
necessary because `ddev composer` runs inside the web container, where a host path in a
path repository would not resolve.

To add another plugin to a bench:

```sh
./dev/link-plugin.sh 4 ~/ddev/some-other-craft-plugin
```

### What to check by hand

The things no unit test covers:

- **Control Panel → Utilities → Database Backup**, including *Download backup*. This is
  the path that forces the encrypted file to keep its `.sql` name, because
  `UtilitiesController` captures the path before the dump runs and asserts the file is
  still there afterwards.
- **`ddev craft db/backup`** then **`ddev craft db/restore <path>`**, confirming the
  restore prompt behaviour for an unencrypted backup.
- **Settings screen**, with and without `SECURE_BACKUPS_KEY` set, and with `compress`
  toggled, checking the openssl and gzip detection banners.
- **A backup taken with `compress` off restoring while `compress` is on**, and the
  reverse. This is the guarantee that the setting is safe to toggle.

### Testing the published package instead of the working copy

A bench normally symlinks this repo through a Composer path repository, and **path
repositories take precedence over Packagist**. So `ddev composer require
anchovyx/craft-secure-backups` in a linked bench installs the working copy and proves
nothing about a release. To test what a user actually downloads, take the link out first:

```sh
ddev composer remove anchovyx/craft-secure-backups
ddev composer config --unset repositories.local-plugins
ddev composer require anchovyx/craft-secure-backups
ddev exec ls -ld vendor/anchovyx/craft-secure-backups   # a directory, not an arrow
ddev exec find vendor/anchovyx -type f                  # 11 files, no dev files
```

Then put the bench back:

```sh
ddev composer remove anchovyx/craft-secure-backups
ddev composer config repositories.local-plugins --json \
  '{"type":"path","url":"/var/www/plugins/*","options":{"symlink":true}}'
ddev composer require 'anchovyx/craft-secure-backups:@dev' -W
ddev craft plugin/install secure-backups
```

Re-adding the repository is the step that gets missed. Without it, `:@dev` installs the
tagged release again, because `@dev` is a stability flag rather than a version constraint.

Craft keys plugin installs by **handle**, so the install record survives a package rename
and `plugin/install` will report "already installed" after one.

### Tearing a bench down

```sh
cd ~/ddev/craft-test-environments/v4 && ddev delete -Oy
rm -rf ~/ddev/craft-test-environments/v4
```
