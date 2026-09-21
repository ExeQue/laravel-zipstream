---
name: laravel-zipstream-upgrade
description: Upgrade an application from one major version of exeque/laravel-zipstream to another, following the package's own upgrade guide.
---

# Upgrading Laravel ZipStream

## When to use this skill
Use it when an application is moving between major versions of `exeque/laravel-zipstream` - the constraint is
being raised, a deprecation is being cleared, or `composer update` brought in a version whose changes have not
been applied yet. For writing new code, use the `laravel-zipstream` skill instead.

## Establish the versions first
Never guess. Both ends decide which parts of the guide apply.

```bash
# From: what is installed right now
composer show exeque/laravel-zipstream --format=json    # "versions" - or grep composer.lock

# To: what the application is moving to
grep -n 'exeque/laravel-zipstream' composer.json        # the constraint being raised
composer show exeque/laravel-zipstream --all --format=json | head -40   # what is available
```

State both versions back to the user before touching anything: *"Upgrading from 0.1.5 to 1.0. That crosses one
major version."* If either is unclear, ask - an upgrade applied from the wrong starting point silently skips
steps.

## The guide is the source of truth
`vendor/exeque/laravel-zipstream/UPGRADE.md` ships with the package, so it is always the copy matching the
version being installed. Read it there rather than from memory or from the web.

Its headings are `## Upgrading from <from> to <to>`. Apply every section between the installed version and the
target, oldest first. Skipping two majors means applying two sections in order, not just the last one.

Each section states its own impact (`**Impact: high**` and so on). Work through the high ones first - they are
the ones that break at runtime rather than at boot.

## Working through a section
For each change the guide describes:

1. Derive what to search for from its before/after examples. The old symbol in the "before" block is the
   pattern: an enum case, a header value, a method signature, a class name.
2. Search the application, not the vendor directory: `grep -rn "<pattern>" app config routes tests`.
3. Apply the change exactly as the "after" block shows it, one section at a time.
4. Keep the diff reviewable. Do not reformat surrounding code.

Two kinds of change need a human decision rather than a mechanical edit, so list them for the user instead of
guessing:

- **A stream or handle whose semantics changed.** Reading once versus seeking back is a property of the calling
  code, not of the call site.
- **Control flow that relied on an exception being swallowed.** The replacement is usually an explicit method,
  but only the caller knows what was meant.

## Config
A published config file is never updated by composer, and republishing it with `--force` throws away whatever
the application configured. Backfill the missing keys into the file that is there instead.

```bash
ls config/laravel-zipstream.php    # published?
```

If it does not exist, there is nothing to do - the package defaults apply. If it does, compare it against the
one the new version ships:

```bash
# A config file calls env(), which does not exist outside a booted application - hence the stub.
php -r 'function env($key, $default = null) { return $default; }
        $new = require "vendor/exeque/laravel-zipstream/config/laravel-zipstream.php";
        $old = require "config/laravel-zipstream.php";
        echo "missing: ", implode(", ", array_keys(array_diff_key($new, $old))) ?: "-", PHP_EOL;
        echo "unknown: ", implode(", ", array_keys(array_diff_key($old, $new))) ?: "-", PHP_EOL;'
```

- **missing**: keys the new version added. Copy each one into the published file, with the comment block the
  vendor copy gives it, in the same order it appears there. Take the vendor file's value verbatim - it is the
  default, usually an `env()` call.
- **unknown**: keys the application still sets that the package no longer reads. Do not delete them on your own;
  check the guide for a rename and tell the user what you found.

Never overwrite a value that is already set, and never reformat the rest of the file. The point is that a
reviewer sees only the added keys in the diff.

## Finish
```bash
composer update exeque/laravel-zipstream
vendor/bin/pest      # or phpunit - the application's own suite
```

Report what changed, what was skipped and why, and anything left for the user to decide. A green suite is not
proof on its own: the guide's runtime changes - cleanup after a failed write, event payloads, stream semantics -
are rarely covered by an application's tests.

## Maintenance
This skill is version-agnostic on purpose: it reads the versions and the guide at runtime. Nothing here needs
editing when a new major ships - only `UPGRADE.md` does, with a new `## Upgrading from <from> to <to>` section.
