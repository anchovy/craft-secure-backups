# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`anchovyx/craft-secure-backups` is a Craft CMS plugin (Craft 4.3.5+ **and** Craft 5, PHP 8.0.2+)
that compresses and encrypts every database backup Craft produces, and reverses both on restore.
It is a standalone plugin package, not a Craft site: there is no `web/`, no `config/`, no DDEV
environment here. To exercise it, it has to be `composer require`d (or path-repo symlinked) into
a real Craft project.

Read `README.md` first. It documents the design rationale in detail and is the source of truth
for user-facing behaviour.

## Commands

```sh
composer install            # required first: there is no vendor/ committed
composer ci                 # check-cs + phpstan + test, i.e. what CI runs
composer check-cs           # ECS lint
composer fix-cs             # ECS autofix
composer phpstan            # PHPStan, level 5, src/ only
composer test               # PHPUnit
```

Tests need no Craft application, no database and no fixtures, and drive the real `openssl` and
`gzip` binaries rather than doubles. `Encrypter::getSettings()` is the single seam that would
otherwise require a booted Craft; `tests/support/TestableEncrypter.php` overrides it. Keep it
that way, and route any new settings read through that method rather than reaching for the
plugin singleton inline.

To switch the analysis target locally without editing `composer.json`:

```sh
composer update --with "craftcms/cms:^4.3.5"                          # Craft 4
composer update --with "craftcms/cms:^5.0.0" --ignore-platform-req=php # Craft 5 (needs PHP 8.2)
```

`--ignore-platform-req=php` is only needed when local PHP is below 8.2; PHPStan parses rather
than executes, so the analysis is still valid.

See `dev/README.md` for the CI matrix and the DDEV benches used for the interactive checks
(Control Panel utility, real `db/backup` and `db/restore`) that none of the above covers.

## Craft 6

Craft 6 is a Laravel rewrite, in alpha and shipping most weeks. It replaces the entire `craft\`
namespace with `CraftCms\Cms\`, so **do not widen `craftcms/cms` to `^6.0`**: supporting it means
a separate major version of this plugin, not a constraint change. Craft 6 does not encrypt
backups itself, so the plugin still has a job there.

Port work is parked until Craft 6 reaches beta, since APIs are still moving. The analysis and
bench how-tos live in `notes/`, which is untracked by design. If `notes/` is absent (a fresh
clone), that knowledge is not in this repository and will need redoing.

`./dev/scaffold-bench.sh 6` builds a Craft 6 bench. It installs no plugin, because none of the
Craft classes this one imports exist there.

## Architecture

Everything hangs off three events on `craft\db\Connection`, registered in
`SecureBackups::_registerEventHandlers()` inside a `Craft::$app->onInit()` callback.

- `src/SecureBackups.php` orchestrates: decides whether to encrypt, builds the decrypting
  restore command, applies the unencrypted-restore policy, cleans up.
- `src/services/Encrypter.php` is the only place that shells out to `openssl`. It knows nothing
  about events or policy.
- `src/models/Settings.php` holds settings. The encryption key is deliberately *not* one of them:
  only the *name* of the env var holding it is stored, so the key never reaches project config.

## Invariants that will silently break things if changed

These are load-bearing. Each exists because of a specific constraint in Craft core, and the
symptom of breaking one shows up only at backup or restore time, not in lint or static analysis.

**The encrypted backup keeps its original `.sql` filename.** Craft's `UtilitiesController`
captures the backup path *before* the dump runs, then asserts the file still exists at exactly
that path. Renaming to `.enc` breaks the Control Panel utility. Encrypted files are identified by
the `Salted__` magic header (`Encrypter::MAGIC`), never by extension.

**Restore works by mutating the `restoreCommand` general config setting, not by swapping
`$event->file`.** `Connection::restore()` never reads `$event->file` back; it uses its own local
variable. The `EVENT_BEFORE_RESTORE_BACKUP` handler sets `restoreCommand`, which Craft reads a few
lines later. `EVENT_AFTER_RESTORE_BACKUP` plus a `register_shutdown_function` both restore the
previous value (the handler is idempotent, because a failed restore throws before the after-event
fires).

**The restore path only works when the base command ends in `< "{file}"`.** That is what gets
rewritten into an `openssl enc -d ... -in "{file}" | gzip -dcf | ...` pipe, so the plaintext SQL
exists only in a pipe. Commands that take the backup as a file argument (PostgreSQL `custom`,
`directory`, `tar` via `pg_restore`) are detected and rejected with an explanatory exception.

**Compression happens before encryption, never after.** Ciphertext does not compress. This is
the entire reason Craft's own zip on the CP path saves nothing, and reversing the order would
silently make the feature pointless rather than break anything visibly.

**`gzip -dcf` is in the restore pipe unconditionally**, regardless of the `compress` setting.
With `-c`, the `-f` flag makes gzip copy unrecognised input straight through, so one command
restores compressed backups, uncompressed ones, and anything predating compression. That
passthrough is what makes `compress` safe to toggle in both directions, and it is verified
behaviour on both GNU and Apple/BSD gzip. Removing `-f`, or making the stage conditional on the
setting, breaks restores of previously-taken backups.

**`proc_get_status()` reports the real exit code only once.** The first call that observes a
process as finished carries it; every call after that returns -1. `Encrypter::_consume()` makes
that first call inside its loop, which is why it returns the exit code to its caller rather than
letting the caller fetch it. Fetching it again anywhere downstream silently yields -1, which
reads as "the process failed" for a process that succeeded.

**The key never appears in `argv`.** Anything in `argv` is visible to any user who can run `ps`.
Two different mechanisms are used because only one works on each side:
- Backup: the plugin owns the process, so the key goes to the child's stdin (`-pass stdin`).
- Restore: Craft owns the process, so a randomly named transient env var is set via `putenv()`
  and cleared immediately afterwards. `putenv()` specifically, because a value present only in
  `$_ENV` or `$_SERVER` is not inherited by child processes.

**The on-disk format stays plain `openssl enc` output.** A backup must always be recoverable with
a one-line `openssl enc -d` on any machine, with no Craft and no copy of this plugin. Do not
introduce a bespoke container format, a custom header, or PHP-side crypto.

**Failing to encrypt deletes the plaintext dump** (`_refuse()`), unless `failIfUnableToEncrypt` is
off. Leaving it is the worst outcome: the operator is told the backup failed while an unencrypted
copy of the whole database sits on disk.

**Unattended sessions never get prompted.** `unencryptedRestore: prompt` refuses outright when
the controller is not an interactive `ConsoleController`, so cron, CI and deploy scripts cannot
load a dump of unknown provenance on the operator's behalf.

## Conventions

Follows Craft's own plugin conventions throughout, and the `craftcms-claude-skills:craft-php-guidelines`
skill applies to every PHP edit here. In particular:

- `// Section Name` headers with a `// ====...` rule beneath, in Craft's order (Constants, Public
  Properties, Private Properties, Static Methods, Public Methods, Protected Methods, Private
  Methods).
- Private methods are `_prefixed`.
- Full PHPDoc on every class and method, including `@throws`. Class-level docblocks carry
  `@author Anchovy <ben@anchovy.nz>` and `@since`.
- Comments explain *why*, particularly where the code is working around a Craft core constraint.
  Preserve that when editing; those comments are the only record of why the approach is what it is.
- ECS imports `craft\ecs\SetList::CRAFT_CMS_4`. Note the namespace is `craft\ecs`, not
  `craftcms\ecs`. `CRAFT_CMS_4` is the newest set craftcms/ecs ships and is correct for Craft 5
  code too.
- No `declare(strict_types=1)` in `src/` (matching Craft core plugin style). `ecs.php` itself has
  it, which is also Craft convention.

Every subprocess is bounded by a timeout with a kill path (`Encrypter::_consume()`,
`Encrypter::_capture()`). Never add a `proc_open`/`exec` call here without one.

## Packaging

This ships to the Craft Plugin Store, so packaging metadata is load-bearing:

- Store listing fields live in `composer.json` under `extra` (`handle`, `name`, `developer`,
  `developerUrl`, `documentationUrl`, `changelogUrl`). `changelogUrl` must stay a **raw**
  GitHub URL, since Craft parses that file to show release notes in the updater.
- `CHANGELOG.md` headings must match `## X.Y.Z - YYYY-MM-DD` exactly or Craft cannot parse them.
- `.gitattributes` `export-ignore` keeps dev files (CI, linter config, this file) out of the
  archive Composer hands to consumers. Add new dev-only paths there.
- Releases are semver git tags. The Store picks up a release once it is tagged and pushed.

## Docs

Behaviour changes need a matching update to `README.md` and a `CHANGELOG.md` entry. The README's
Limitations section in particular is a promise to users about what this does not cover.
