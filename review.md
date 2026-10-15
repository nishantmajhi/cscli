# Review — cscli

First reviewed 2026-09-04. Remediated 2026-09-30 on branch `cleanup/2026-09-30`.
51 tests, 168 assertions, 0 failures, about seven seconds (`composer install`,
then `php tests/run.php`). Nothing pushed.

**One interface addition, flagged here as the standing constraint requires:**
`cscli view` has a new `--language` / `-l` option. It was the review's P2, and
every existing invocation behaves exactly as it did. **One behaviour change you
can see:** `-q` now prints the command's result and its errors, where it used
to print nothing at all. See "-q printed nothing" below.

## Corrections to the first review

**"There is very little to fix" was wrong.** The review read the code and the
README side by side, found them in agreement, and praised both. The README's
"Design notes" make five promises; checked by running the client against the
real API, three were false, and the requirements section was false too:

| The README said | What happened |
| --- | --- |
| `view`'s default output is exactly the source bytes | it went through Symfony's formatter, see below |
| `-q` does not change what data is shown | `-q` showed nothing, not even errors |
| errors are plain English, no stack traces | `--json` died with a PHP fatal and a stack trace on one kind of name |
| PHP 8.1+ | `symfony/console ^7.0` needs 8.2; `composer install` fails on 8.1 |

The two that held are that errors go to stderr with exit 1, and that
`--output` asks before overwriting. The review never ran the client. The
lesson is the one item 45 in `CONTINUE-HERE.md` already records: when a README
states a rule, write the assertion before believing it.

**Finding 1 asked for a change "in one commit across both projects". The API
half was already done.** cscli-api's own pass (2026-09-18) sorted the
languages so the one-segment `/view/{program}` has a defined winner, added an
`X-Cscli-Language` header, and added `/view/{language}/{program}`. So this was
a client-only change and cscli-api was not touched. Also: no two languages in
cscli-api's real `src/` share a file name today, so the ambiguity is real but
has never happened.

**Finding 2 (base URI) needed nothing.** `Application.php` already read
`CSCLI_API_URL` with a default, and the README already documented it. A
`--api-url` option was not added: the environment variable does the job, and
an option is interface.

**Findings 3 and 4 were checks, and both hold.** Nothing sets `verify => false`
(a test now asserts it). Every non-2xx status maps to an exception: 404 to
`ApiNotFoundException`, everything else to `ApiException` naming the status.
For a 500 the server's own body already says it is the server's fault.

**"Nothing to remove" missed one thing.** The README's "Development" section
described a hand-built `vendor/autoload.php` that is not in this repository.

**The note about coordinating with cscli-api's path validation** expected the
`list` and `view` routes to change shape. They did not; a route was added.

## What was wrong, and what was done

Every defect below was reproduced against the first commit before it was
fixed. Each fix has a `regression:` test that runs `bin/cscli` from the
working copy and from the first commit (rebuilt from git) against the same
server, and fails if the original passes.

### `view` rewrote source code containing console markup

`$output->write($source)` formats by default, so the source went through
Symfony's tag parser:

```
source:   A <info>x</info> </> y \< z
printed:  A x  y < z                  (piped, or --no-ansi)
          A \e[32mx\e[39m  y < z      (--ansi, or TERM=xterm on Windows)
```

Style tags were stripped or turned into escape codes, every `</>` vanished
(a JSX fragment's closing tag), and `\<` lost its backslash. Exit 0. Under Git
Bash on Windows, `TERM=xterm` makes Symfony decorate even a redirected stream,
so `cscli view x > x` wrote ANSI codes into the file. `--output` was never
affected. Nothing in cscli-api's `src/` contains those sequences today. Fixed
with `OUTPUT_RAW`.

### `-q` printed nothing, including errors

```
cscli view factorial.php -q > factorial.php     # an empty file, exit 0
cscli languages --json -q | jq .                # no input
cscli view nope.php -q                          # exit 1, no reason given
```

The README says quiet "does not change what data is shown". Symfony's own
`--help`, which every user sees, says `-q` means "Only errors are displayed".
The code wrote its data and its errors at normal verbosity, which is exactly
what `-q` suppresses, so it met neither promise.

`src/Console/Verbosity.php` sorts output into three kinds. The result (source,
list, JSON) and errors survive `-q`. Incidental messages ("Saved to", "No
programs found") do not. `--silent` hides everything. This changes what is on
screen under `-q`, which is why it is at the top of this file.

### `.` and `..` were resolved as path steps

`rawurlencode()` does not encode dots, and Guzzle resolves requests against
the base URL by RFC 3986:

```
cscli programs ..   ->  GET /        the welcome text listed as programs, exit 0
cscli view ..       ->  GET /        the welcome text printed as source, exit 0
cscli programs .    ->  GET /list/   a 400 about a missing language
```

Nothing unsafe was reachable, because cscli-api validates every name. But the
client answered a question it was not asked and reported success. A segment
that is only dots is now sent as `%2E`s, and the server answers with its
ordinary 404.

### List output trusted the names it was given

The same code sat in both list commands, as two identical copies:

- **A name that is not valid UTF-8 killed `--json`.** `json_encode()`
  returned `false`, `writeln(false)` is a `TypeError` under strict types, and
  the result was a PHP fatal with a stack trace and exit 255. cscli-api's
  text format sends names as raw bytes, so a Latin-1 file name on a Linux host
  is enough. It is U+FFFD now, as in cscli-api's own JSON.
- **`--json` went through the formatter.** A name containing `\<` became JSON
  with an invalid escape that no parser accepts. It is written raw now.
- **Human lists interpreted names as markup.** `<info>x</info>.php` showed as a
  green `x.php`. Names and headings are escaped now.
  `OutputFormatter::escape()` was checked to round-trip `<`, `>`, `\<` and a
  trailing backslash in Symfony 7.4.

None of these can happen with today's `src/`. The tests use a small canned
server, because NTFS cannot store either kind of name. The shared code is now
`src/Console/Listing.php`.

### The PHP floor was not installable

`composer.json` said `>=8.1`, and every `symfony/console` 7.x needs 8.2.
Confirmed with Composer at `platform.php 8.1.0`: resolution fails. Raised to
8.2, which is what was already true; PHP 8.1 has been end of life since
December 2025. There was no lock file, so each install resolved afresh; on
PHP 8.4 that picked `symfony/string` 8.0, which needs 8.4. `config.platform.php`
now pins resolution to 8.2, and `composer.lock` is committed.

### P2 — `view` could not name the language

`cscli view factorial.php -l python` now calls
`/view/python/factorial.php`. Without `-l`, nothing changed. An empty value
counts as none.

**Compatibility:** against cscli-api's *original* code, `-l` fails with
"Program 'python' not found", because that code reads the language as the
program. This was checked against the API's baseline commit, not assumed.
Without `-l`, the client works against either version, except that the
original API cannot list `c#` or `c++` (cscli-api's review has that story).

### P3 — tests, LICENSE, README

- 51 tests. There were none. See "The suite" below.
- `LICENSE`: MIT, word for word as cscli-api's. `composer.json` already
  declared MIT.
- README rewritten. It points at `../cscli-api` as well as GitHub, as the
  review asked.

## Deliberately not done

- **Copy was not changed.** The server's hints ("See /list/{language_name}")
  are shown to CLI users verbatim. The connection error does not name the URL
  it tried. Under `-n`, an overwrite refusal says only "Aborted.". All three
  are wording, and wording is interface.
- **`cscli languages` against an empty server** prints a heading and nothing
  under it, where `programs` prints a warning. Cosmetic.
- **`X-Cscli-Language` is not used.** The client could report, say under
  `-v`, which language a bare name was found in. That would be new output;
  `-l` already resolves the ambiguity.
- **An unreachable server on Windows takes the full 10 s timeout** with PHP
  builds that lack `curl`, where Guzzle falls back to PHP streams. Seen, not
  investigated further, and `connect_timeout` was not added: it changes how
  long a slow server gets, which is a judgement about deployments nobody here
  knows. The suite uses an `.invalid` host for this case, which fails at once.
- **Only PHP 8.4 was run.** The lock resolves for 8.2, but no 8.2 or 8.3 PHP
  was available. Both PHP 8.4 builds here were used: the one on `D:` (no
  `curl`) and Herd's (with `curl`), so both Guzzle handlers are covered.

## The suite

```
tests/run.php               runner; `php tests/run.php Command` runs one file
tests/Harness.php           cscli-api's harness, plus regression() and skip()
tests/ApiClientTest.php     every request and failure, through Guzzle's MockHandler
tests/CommandTest.php       the commands through the real Application (ApplicationTester)
tests/EndToEndTest.php      bin/cscli as a process, against cscli-api over php -S
tests/support/              the fixture tree, the servers, the process runner
```

`ApplicationTester` is used, not `CommandTester`, because `-q`, `--silent` and
`-n` only become a verbosity inside `Application::run()`. A `-q` test written
with `CommandTester` tests a setting no user can reach.

`EndToEndTest` loads `../cscli-api/utils/dispatch.php`, or the one at
`CSCLI_API_DIR`, and serves a fixture tree through it. So the client is tested
against the server's own route table, not a stand-in. Without the sibling, ten
tests skip and the suite still passes (122 assertions instead of 168).

`tests/support/Cli.php` rebuilds the first commit's `bin/` and `src/` from git
into a temporary directory. It uses an autoloader that maps `Cscli\` there and
reuses this checkout's `vendor/`. The two versions cannot share a process
because the class names are the same. So each `regression:` test runs as two
real processes, and fails if the original passes.

Each commit was checked out into its own worktree, `composer install`-ed from
its own lock, and tested there: no suite, 30, 33, 40, 42, 46, 51, 51, 51, 51,
all green with no skips. The final commit was also run without the sibling
(122 assertions, 10 skips, 0 failures) and under Herd's PHP (168, 0).
cscli-api's own suite was re-run afterwards, unchanged at 86 tests and 216
assertions.

## Notes for a future session

- **The harness had a bug that only showed without the sibling.** A
  regression body that skipped then "passed" against the original, and was
  reported as a failure. Fixed in the last commit. If you write a
  `regression()` body that can skip, call `skip()`, not a bare `return`.
- **Windows and `ApplicationTester::getDisplay()`:** it keeps `PHP_EOL`, so
  tests of formatted output use `getDisplay(true)`. Tests of raw source must
  not, or they cannot see a rewrite.
- **The Bash tool's `sed` eats backslashes in replacement text**, so
  `use Cscli\Console\Verbosity;` became `use CscliConsoleVerbosity;`. That is
  the same trap item 55 records for heredocs. Namespaced PHP is edited with
  the Edit tool.
- Composer ran from Herd's PHP, with `COMPOSER_HOME` and
  `COMPOSER_CACHE_DIR` on `D:\dev-tools\`. The `D:` PHP has no `openssl` or
  `zip`, so it cannot run Composer, but it runs the suite.

## Licence, 2026-10-02

The author chose one licence for all of their projects: free for personal,
non-commercial use, modifiable for the user's own use, and not to be
distributed, original or modified. No standard licence says that, so `LICENSE`
is a short custom text, the same in every project apart from the year and what
it excludes. The README's licence section says the same, and `composer.json`
declares `proprietary`, Composer's word for a licence with no SPDX identifier.
Anything above that calls the licence an open question, or calls the project
MIT, is superseded.

## CI, 2026-10-02

`.github/workflows/ci.yml` runs `composer install` and `tests/run.php` on PHP
8.4, on `ubuntu-latest`, with full history for the root-commit tests. Nothing
has been pushed, so it has not run on GitHub yet. Every job was run before
committing, from a fresh clone of this branch, the way a runner would run it:
Linux jobs in a throwaway Ubuntu 24.04 (the image `ubuntu-latest` uses) with
the same tool versions. 51 tests, 122 assertions.

The ten tests that load `../cscli-api`'s dispatcher skip, as anywhere that
repository is not beside this one. Checking it out alongside in CI would work
once its cleanup branch is on its default branch.
