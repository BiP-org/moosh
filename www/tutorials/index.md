---
title: tutorials
layout: default
---

Tutorials
=========

Moodle code generation
----------------------
<iframe width="560" height="315" src="//www.youtube.com/embed/pIaH3MDIZhU" frameborder="0" allowfullscreen></iframe>

Getting theme information
-------------------------
<iframe width="560" height="315" src="//www.youtube.com/embed/dXAFQOgoHfA" frameborder="0" allowfullscreen></iframe>

Using plugin-list commands
-------------------------
# General

Plugin-list commands are a toolkit to maintain plugin installation for an already installed moodle core using a directory layout.
This way you can automate the plugin maintaince (update/remove/uninstall/install) using command line tools using a "declarative plugin list".

## Directory layout

This targets the "declarative plugin list" layout used elsewhere in this
toolkit one subdirectory per plugin, named after
its Frankenstyle component, e.g.:

```
plugins/
├── block_fastnav/
│   ├── version          # desired version, e.g. "2024010100"
│   └── checksum          # sha256 of that version's zip (see below)
├── mod_board/
│   ├── version
│   └── checksum
└── package_kaltura/
    ├── version
    └── bin/
        └── get_latest_plugin_version.sh
```

# `moosh plugin-list-update`

Keeps the `version` file of one or more locally-tracked Frankenstyle plugin
directories in sync with the latest version available from moodle.org that
is compatible with a given Moodle release.

This command only **resolves and pins version numbers** (and, optionally,
checksums) — it never downloads a plugin into your Moodle installation and
never installs/uninstalls anything. It's the "what should be installed"
half of the workflow; `moosh plugin-install` (or a future
`plugin-list-install` command, still in progress) is the "make it so" half.


## Usage

```
moosh plugin-list-update [-d <directory>] [-v <release>] [-m <moodle-root>]
                          [-p <plugins.json path>] [-n|--dry-run]
                          [-r <proxy>] [-t <token>] [--no-checksum]
                          [component ...]
```

| Option | Description |
|---|---|
| `component ...` | Zero or more Frankenstyle component names. None given → every subdirectory of `-d` is processed. |
| `-d, --directory` | Directory to scan for plugin subdirectories. Default: current directory. |
| `-v, --version` | Moodle major version to match plugin compatibility against, e.g. `4.3`. Default: auto-detected from the bootstrapped site. |
| `-m, --moodle-root` | Working directory used when invoking a `package_*` component's `bin/get_latest_plugin_version.sh`. Default: the parent directory of `-d`. |
| `-p, --path` | Path to the cached `plugins.json`. Default: `~/.moosh/plugins.json` (auto-refreshed if missing/stale, same as `plugin-list`). |
| `-n, --dry-run` | Report what would change; don't write any files (including `checksum`). |
| `-r, --proxy` | Proxy URI, e.g. `tcp://user:pass@host:port`. Also respects the `http_proxy` env var. |
| `-t, --token` | Moodle Marketplace API token (Bearer token for the download request). Also respects `MOODLE_MARKETPLACE_TOKEN`. |
| `--no-checksum` | Skip checksum pinning entirely — no zip downloads at all, `checksum` files are left untouched. |

## Per-component behaviour

For each candidate directory:

| `version` file state | Result |
|---|---|
| missing | created, with the latest compatible version |
| older than the latest compatible version | updated to the latest compatible version |
| already at the latest compatible version | left untouched |
| contains `0` | left untouched — this is the pinning convention used elsewhere in this toolkit to mark a plugin for uninstall (see `install_requested_version()`/`uninstall()` in `moodle_plugins_lib.rc`); `plugin-list-update` never overwrites it |
| newer than the latest compatible version currently available | left untouched — this command never downgrades a version a human deliberately pinned |
| no available version supports the target Moodle release | left untouched; a `support_status` file is written next to it containing `not supported for moodle core <release>` (matching `get_support_status()`'s existing marker), and removed again automatically once a compatible version exists |

Each component is reported on its own line: `CREATE`, `UPDATE`, `OK`, `SKIP`,
`ERROR`, or (in `--dry-run`) `WOULD CREATE` / `WOULD UPDATE`. Processing
continues even if one component fails or is skipped for a missing
directory; the command exits non-zero at the end if anything did.

## `package_*` components

Directories named `package_*` aren't on moodle.org, so instead of consulting
`plugins.json` this command calls their own `bin/get_latest_plugin_version.sh`
(no arguments, working directory set to the resolved Moodle root, with the
`__config_plugin_directory` and `__moodle_root_directory` environment
variables set the same way `moodle_plugins_lib.rc` sets them for its own
`package_*` handling). The script's stdout, trimmed, must be a plain integer
version number.

Checksum pinning (below) is **not** available for `package_*` components:
there's no generic download URL for them in `plugins.json`.

## Checksum pinning

Unless `--no-checksum` is given, whenever a component's `version` file is:

- **created or updated** — the newly-resolved version's zip is downloaded
  and its sha256 is written to `<component>/checksum`, replacing any
  previous (now-stale) value, or
- **already at the latest version but has no `checksum` file yet** — the
  same download/pin happens once, to backfill it (this doesn't re-run on
  every invocation once a `checksum` file exists — it's not re-verified
  against moodle.org on every run, only recomputed when the pinned version
  actually changes).

The download reuses the same disk cache as `plugin-clamscan`
(`Moosh\PluginCache`) and is verified through `Moosh\PluginChecksum::verify()`
— the same integrity check used by `plugin-install` and `plugin-clamscan`.
That means it also honours the `MOOSH_EXPECTED_SHA256` environment variable
if you set it: since it's a single, global value for the whole command
invocation, that's only meaningful when you're updating exactly one named
component (`moosh plugin-list-update mod_board`), not when scanning a whole
directory of plugins.

If a download or checksum verification fails, that component is reported as
`ERROR` (and the command's exit code reflects it), but the `version` file it
already wrote is **not** rolled back — the next run will simply retry
pinning the checksum, since `checksum` is still missing.

## Exit codes

`0` if every component was processed without error. `1` if any component
errored, was skipped because its directory doesn't exist, or a checksum
couldn't be pinned.

## Examples

Update every plugin directory found in the current directory:

```
moosh plugin-list-update
```

Only update `block_fastnav` and `mod_board`, against Moodle 4.3:

```
moosh plugin-list-update -v 4.3 block_fastnav mod_board
```

Report what would change without writing anything:

```
moosh plugin-list-update -n
```

Update without downloading zips to pin checksums (fastest, e.g. for a quick
version-only sync):

```
moosh plugin-list-update --no-checksum
```

Re-verify and re-pin the checksum for one specific, already up-to-date
plugin against a value you already trust:

```
MOOSH_EXPECTED_SHA256=<sha256> moosh plugin-list-update mod_board
```
