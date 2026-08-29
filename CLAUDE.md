# local_cleanup — notes for Claude

A Moodle `local` plugin that finds and removes large or orphaned files, and prunes old
submissions, backups, grade history and logs. **The repository root is the plugin root**: in a
running site these files live at `<moodle>/local/cleanup/`. The code cannot run standalone — the
entry-point pages (`files.php`, `ghost.php`, `open.php`, `remove.php`, `download.php`)
`require_once` the host's `config.php` three levels up.

## Commands

| What | Command |
|---|---|
| Install | none — no `composer.json`, nothing to install |
| Test | `moodle-plugin-ci phpunit` and `behat` — CI only; there is no local Moodle to run them against |
| Lint | `docker run --rm -v "${PWD}":/app -w /app php:8.1-cli php -l <file>` |
| Build | none — no compiled assets, no `amd/` directory |
| Run | `hosted` — needs a Moodle 4.1+ install; the CLI entry points are in `README.md` |

There is **no `php` on this host**, so lint runs in a container; anything else needing PHP goes
through the same prefix. The image is pinned to `php:8.1-cli`, the lowest PHP in the CI matrix
(8.1 / 8.3 / 8.4), so a pass here also holds for the newer two.

The checks that gate a merge are in `.github/workflows/ci.yml`, run through `moodle-plugin-ci`:
`phplint`, `codechecker --max-warnings 0`, `phpdoc --max-warnings 0`, `validate`, `savepoints`,
`phpunit`, `behat`, `mustache`, `grunt`, `phpcpd`, `phpmd`, plus a grep that **fails the build
on any direct `$_GET` / `$_POST` / `$_REQUEST`**. They run on PostgreSQL across three PHP and
Moodle versions and on MySQL 8 — the second engine exists because a `GROUP BY` defect survived
two years of single-engine CI. None of it is reproducible locally, so CI is the source of truth
and runtime verification here is limited to lint.

Two style rules cost the most round trips: an inline comment must start with a capital letter,
a digit or `...` (a lowercase function name at the start of a comment fails), and a class
opening brace must not be followed by a blank line.

<!-- toolkit:begin family-rules -->

## Moodle conventions

This repository root **is** the plugin root; in a running site it lives under the matching
`<moodle>/<type>/<name>/` directory. The code cannot run standalone — it needs a Moodle install
around it, which is why entry-point pages `require_once` the host's `config.php` by relative
path.

### Coding style

- **4 spaces**, never tabs. Unix line endings. No trailing whitespace.
- Aim for **132 characters** per line; never exceed 180. Language strings are exempt.
- **Classes and functions:** lowercase with underscores (`some_custom_class`), prefixed with the
  component name where the API expects it. **Constants:** uppercase with the component prefix.
- **Variables:** lowercase, whole English words, plural for arrays.
- `<?php` only, and **no closing `?>`** at end of file.
- Non-entry-point files start with `defined('MOODLE_INTERNAL') || die();`.
- Short array syntax `[]`. `true` / `false` / `null` lowercase.
- Opening brace on the same line; braces always, even for one-line bodies. `else if`, not
  `elseif`.
- New classes live under `classes/` with a namespace matching the path. One namespace per file,
  individual `use` statements.
- New code carries **type hints and return types**, and a docblock with `@param` / `@return` in
  lowercase type names.
- Prefer `$var ?? $default` over long ternaries.

### SQL

- **Every variable goes in a placeholder.** Named parameters where there is more than one.
  String concatenation into a query is a defect, not a style preference.
- `{table_name}` braces for tables. Keywords UPPERCASE. Double-quoted query strings.
- `AS` for column aliases, never for table aliases. `JOIN` rather than `INNER JOIN`; no
  `RIGHT JOIN`. `<>` rather than `!=`.
- Whole queries through `$DB->get_records_sql()` / `get_recordset_sql()` / `execute()`;
  fragments through the `_select()` variants.
- Use `get_recordset_*` and iterate when the result set can be large — `get_records_*` loads
  everything into memory.

### Security — the parts that are easy to skip

Each of these is a **blocker**, because bypassing them produces code that looks correct and
works in testing.

- **Never read `$_GET`, `$_POST` or `$_REQUEST` directly.** Use `required_param()` /
  `optional_param()` with the tightest `PARAM_*` type that fits (`PARAM_INT`, `PARAM_ALPHANUMEXT`,
  `PARAM_TEXT`). The type *is* the validation.
- **`require_login()` on every entry point**, with the course or module context where relevant.
- **`require_capability()` for authorisation**, checked against the real context. Hiding a menu
  item is not access control — the URL is still reachable. Capabilities are declared in
  `db/access.php` with sane `archetypes` and a `riskbitmask` that reflects what they allow.
- **`require_sesskey()` on anything that changes state.** A GET that deletes, or a POST with no
  session key, is a cross-site request forgery hole.
- **Escape on output**: `s()` for plain text in HTML, `format_string()` for short strings such
  as names, `format_text()` for anything with markup. Echoing a database value directly is XSS.
- **Never** `eval()`, backticks, `preg_replace()` with `/e`, `goto`, or `unserialize()` on
  anything a user can influence.
- **Files go through the Moodle File API**, not `fopen`/`move_uploaded_file` into a web-readable
  path.
- **Spreadsheet and CSV exports carry a formula-injection risk**: a cell beginning `=`, `+`, `-`
  or `@` is executed when the file is opened. Write user-controlled text with
  `setCellValueExplicit(..., DataType::TYPE_STRING)`, and write real numbers as
  `TYPE_NUMERIC` so they stay sortable.

### Things that break only on the live site

- **Bump `$plugin->version` in `version.php`** whenever you add or move a file under `classes/`,
  or change `db/` schema, `db/caches.php` or `db/tasks.php`. Moodle caches the class map and the
  schema version; without the bump the site keeps running the old code and you get a
  "class not found" that reproduces nowhere else. When unsure, bump — it is cheap.
- Schema changes belong in `db/upgrade.php` (or `db/tables/`) guarded by a version check, and
  the guard version must match the new `version.php` value.
- Capability changes require the version bump too, or the new capability will not exist.

### JavaScript

- AMD/ES6 modules in `amd/src`, built to `amd/build`. Never edit `amd/build` by hand and never
  commit `node_modules`.
- camelCase for variables and functions, PascalCase for classes, UPPERCASE for constants.
- jQuery and YUI are not used in new code, only where interfacing with legacy code.
- Return a promise or a value; chain rather than nest.

<!-- toolkit:end family-rules -->

## This project specifically

- **Authorisation is by capability**, declared in `db/access.php`. `local/cleanup:view` reads
  the reports and is granted to `manager`; `local/cleanup:deletefiles` removes a file and is
  granted to **no archetype**, because it destroys content irreversibly. Every entry point does
  `require_login()` then `require_capability(..., context_system::instance())`. Gate a new page
  on `view` and anything destructive on `deletefiles`.
- **`remove.php` calls `require_sesskey()` before deleting.** Any new state-changing endpoint
  must do the same. Note the confirmation link has to carry `sesskey` explicitly.
- **`html_writer::table()` and `html_writer::link()` do not escape.** Every database value that
  reaches a cell or link text in `files.php` and `ghost.php` is wrapped in `s()`; keep it that
  way for anything new.
- **Settings live under `local_cleanup/`** and are read only through `classes/config.php`, which
  returns typed values. Do not add a `$CFG->cleanup_*` read; add an accessor. A setting that
  widens what gets deleted needs a safe default — the component list starts empty on purpose,
  and `config::CLEANABLE_COMPONENTS` is a fixed allowlist so a stray database value cannot
  widen the blast radius.
- **This plugin's whole purpose is deletion**, largely from `files` and `local_cleanup_files`
  plus grade and log tables. Treat `classes/steps/*` as data-safety critical: bounded scope,
  idempotent re-runs, and never widen a `WHERE` without knowing what it now matches. Every step
  has a test asserting both what it deletes and what it leaves, except
  `classes/steps/CourseModulesCleanup.php`; match that pattern. Despite its name,
  `FilesCheckout` deletes — there is no dry-run mode anywhere yet.
- **Scheduled tasks must do no work in their constructor.** Moodle instantiates them when it
  re-registers a component, and `ghost.php` builds one purely to read its next run time.
  `classes/task/cleanup.php` reads configuration in `execute()` for that reason.
- **`config.php` is gitignored** and belongs to the host Moodle; no credentials live here.
- **`README.md` carries the operator-facing detail** — cron setup, the recommended core
  maintenance tasks, the stuck-module fix. Link to it rather than restating it.
