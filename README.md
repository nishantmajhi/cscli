# cscli

A command-line client for [cscli-api](../cscli-api), an HTTP API that serves a
tree of example programs, one directory per language. `cscli` asks it the three
things it knows: which languages there are, which programs are in a language,
and what is in a program.

```sh
cscli languages                        # c#, c++, php, python, ...
cscli programs php                     # factorial.php, hello_world.php, ...
cscli view factorial.php > factorial.php
```

The server is the sibling directory `../cscli-api`, which is also published as
[nishantmajhi/cscli-api](https://github.com/nishantmajhi/cscli-api). Start it
with `php -S localhost:6300 index.php` from that directory; its README has the
rest.

## Installing

You need PHP 8.2 or later and [Composer](https://getcomposer.org).

```sh
composer install
php bin/cscli --help
```

`composer.lock` is committed and resolved for PHP 8.2, so every install gets
the same Symfony Console and Guzzle.

PHP's `curl` extension is not needed; Guzzle uses PHP's own streams without it.
`openssl` is needed for an `https://` API URL, and Composer needs it to
download anything.

### If `composer install` stops partway

```
The zip extension and unzip/7z commands are both missing, skipping.
```

Composer unpacks packages with PHP's `zip` extension, or with `unzip` or `7z`
if one is on `PATH`. Run `php --ini` to find the `php.ini` your command line
uses, change `;extension=zip` to `extension=zip`, and open a new terminal. On
Debian or Ubuntu the extension is a package instead: `sudo apt install
php-zip`. Installing 7-Zip (Windows) or `unzip` (elsewhere) works too.

### Putting it on `PATH`

On macOS and Linux, link it into a directory already on `PATH`:

```sh
chmod +x bin/cscli
sudo ln -s "$(pwd)/bin/cscli" /usr/local/bin/cscli
```

On Windows, a PHP script is not directly executable, so put a `cscli.bat`
in a directory that is on `PATH`:

```bat
@echo off
php "C:\path\to\cscli\bin\cscli" %*
```

## Pointing it at a server

It talks to `http://localhost:6300` unless `CSCLI_API_URL` says otherwise.

```sh
export CSCLI_API_URL=http://api.example.com     # bash, zsh
set CSCLI_API_URL=http://api.example.com        # cmd; setx to keep it
$env:CSCLI_API_URL = "http://api.example.com"   # PowerShell
```

An empty value means the default. A URL with a path works:
`http://host/cscli-api` asks for `http://host/cscli-api/languages`.

## Commands

```sh
cscli languages                  # the languages, as a list
cscli languages --json           # {"languages": [...]}

cscli programs php               # the programs in one language
cscli programs php --json        # {"language": "php", "programs": [...]}

cscli view factorial.php                    # the source, to stdout
cscli view factorial.php -o factorial.php   # or to a file
cscli view factorial.php -l python          # the python one, not the php one
```

`cscli <command> --help` has the details and examples for each.

### Which `factorial.php`?

A program name on its own is looked for in every language, in alphabetical
order, and the first match is printed. If two languages both have a file of
that name, `--language` (`-l`) says which one you want. `cscli programs
<language>` shows what each language has.

`--language` needs a cscli-api with the `/view/{language}/{program}` route.
The API's original release does not have it, and answers "Program 'python'
not found", reading the language as the program name. The same release cannot
list `c#` or `c++` at all, because it never decoded `c%23`. Everything else
works against either.

## What it prints, and where

- **The result goes to stdout; everything else goes to stderr.** An error is
  a sentence, not a stack trace, and the exit code is 1. A connection failure
  says so rather than showing Guzzle's message.
- **`view` prints the source byte for byte.** Nothing is added, not even a
  final newline, so `cscli view x > x` is a faithful copy. `--output` writes
  the same bytes.
- **`--json` is for scripts.** It is the parsed list, pretty-printed, and a
  name that is not valid UTF-8 comes through as U+FFFD rather than breaking
  the output.
- **`-q` hides the chatter, not the answer.** Under `-q` you still get the
  source, the list or the JSON, and you still get errors. What goes is the
  "Saved to" confirmation and the "No programs found" warning. `--silent`
  hides everything, and the exit code is all that is left.
- **Nothing is overwritten without asking.** `view --output` to an existing
  file asks first. With `-n`, or `-q`, which implies it, the answer is no and
  the command stops with "Aborted." and exit 1.

These follow [clig.dev](https://clig.dev).

## How it is put together

```
bin/cscli                     finds the autoloader and runs the application
src/Application.php           the three commands, and where the API URL comes from
src/Api/ApiClient.php         the HTTP calls: names in, text or an ApiException out
src/Command/                  one class per command
src/Console/Listing.php       reading the API's lists, and writing them back out
src/Console/Verbosity.php     what -q may hide
tests/                        the suite
```

`ApiClient` is the only class that knows there is HTTP. Commands get plain
text back, or an `ApiException`; a 404 is the subclass `ApiNotFoundException`
so a command can suggest what to try next.

Two things in it are easy to undo by accident. Every name becomes one
percent-encoded path segment, and a name that is only dots is encoded too:
`rawurlencode()` leaves `..` alone, and Guzzle would then resolve `list/..` to
the API's root. And anything that came from the server is written with
`OUTPUT_RAW` or escaped first, because Symfony Console treats `<info>`, `</>`
and `\<` in ordinary output as formatting.

## Tests

```sh
composer install
php tests/run.php            # everything
php tests/run.php Command    # one file
```

51 tests, about seven seconds, most of it spent starting processes. There is
no test framework: the harness is the same small one cscli-api uses, and the
suite uses Guzzle's `MockHandler` and Symfony's `ApplicationTester`, which
come with the dependencies.

| | |
| --- | --- |
| `ApiClientTest` | every request the client makes and every way one can fail, through `MockHandler` |
| `CommandTest` | the three commands through the real `Application`, including `-q`, `--silent` and `-n` |
| `EndToEndTest` | `bin/cscli` as a process, against cscli-api's own route table over a real socket |

`EndToEndTest` loads `../cscli-api/utils/dispatch.php`, or the one under
`CSCLI_API_DIR`, and serves a fixture tree through it with `php -S`. It skips
if that file is not there.

Its tests whose names start `regression:` are run twice: against this code,
and against the first commit, rebuilt from git into a temporary directory. They
must pass on the first and fail on the second. A regression test that passes
against the unfixed code is not testing the fix, and the runner says so.

## Licence

Free for personal, non-commercial use. You may use, study and modify this
software for yourself, but you may not use it commercially, and you may not
share or publish it, in its original form or modified. See [LICENSE](LICENSE)
for the full terms.
