# Satisfactory Assets Extractor

An unattended pipeline that keeps a data + icon set for the game
[Satisfactory](https://www.satisfactorygame.com/) in sync with Steam. On a
schedule it detects new game builds, extracts the shipped documentation and
icon textures, normalizes them, and hands the result off to a co-located API.

It runs headless on Linux (developed against Ubuntu Server 24.04).

## What it does

For each branch (`stable` / `experimental`), once per run:

1. **Detect** - query Steam for the current build id (anonymous, no login).
2. **Download** - `steamcmd`-update the game client for that branch.
3. **Version** - read the human game version (e.g. `1.2.3.1`) + engine version from the install.
4. **Docs diff** - locate the `Docs` file and skip the rest if it hasn't changed.
5. **Parse** - run the [`satisfactory-tools/docs-parser`](https://github.com/SatisfactoryTools/DataUtils) package (once per variant: default and `-ficsmas`), producing the item / recipe / building / schematic data and the list of referenced icon textures.
6. **Extract** - pull those textures out of the UE5 pak files with [CUE4Parse](https://github.com/FabianFG/CUE4Parse) and normalize each to 256×256 and 64×64 PNGs.
7. **Content-address + dedup** - name each image by a hash of its contents, so identical icons are stored once and unchanged images are never re-uploaded.
8. **Rewrite + publish** - replace every icon reference in the parsed data with its image id, wrap it as `{ "data": …, "metadata": {} }`, and drop the images + versioned JSON into the API's data directory, then trigger the API import.

State (per-branch build id / docs hash, published image ids, run history) lives in MariaDB. Runs are locked per-branch, guarded for disk space and extraction failures, and report success/failure to a log file and (optionally) Discord.

## Architecture

Three cooperating components, decoupled by a CLI + JSON/file contract:

| Component        | Tech                                        | Role                                                                                                                      |
|------------------|---------------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `orchestrator/`  | PHP 8.5 (Symfony Console)                   | The pipeline: version check, download, docs diff, publish, state, scheduling, notifications. Shells out to the other two. |
| `extractor-net/` | .NET 10 + CUE4Parse + SkiaSharp             | Decodes UE5 textures and writes normalized PNGs + per-asset content hashes.                                               |
| docs parser      | `satisfactory-tools/docs-parser` (Composer) | Parses the game `Docs` into structured data. Driven as a console command.                                                 |

The heavy lifting that *must* be .NET (CUE4Parse) is isolated in `extractor-net/`; everything else is PHP.

## Repository layout

```
orchestrator/     PHP orchestrator (bin/extractor, src/, config/)
extractor-net/    .NET image extractor (Program.cs, Extractor.csproj)
systemd/          systemd units
data/             runtime working dir (git-ignored): downloads, artifacts, images
```

## Requirements

- Linux, `steamcmd`, a Steam account that **owns Satisfactory** (the Docs + icon paks ship only with the game client, not the free dedicated server)
- PHP 8.5 (`pdo_mysql`, `curl`, `iconv`) + Composer
- .NET 10 SDK/runtime
- MariaDB or equivalent
- Ample disk (~30 GB per branch for the game install)

## Setup

Run everything below from the repository root unless noted.

**1. Build the components**

```bash
# PHP orchestrator (installs the docs-parser package + Symfony)
cd orchestrator && composer install && cd ..

# .NET image extractor
cd extractor-net && dotnet build -c Release && cd ..
```

**2. Create the database + user** (MariaDB/MySQL)

```bash
sudo mariadb <<'SQL'
CREATE DATABASE IF NOT EXISTS sf_extractor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sf_extractor'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON sf_extractor.* TO 'sf_extractor'@'localhost';
FLUSH PRIVILEGES;
SQL
```

**3. Configure**

```bash
cp orchestrator/config/config.example.yaml orchestrator/config/config.yaml
```

Edit `orchestrator/config/config.yaml` (it's commented throughout). At minimum set:

- `database.*` - DSN / user / password matching step 2
- `steam.username` - the Steam account that **owns Satisfactory**
- `extraction.dotnet` - path to the .NET 10 runtime (e.g. `dotnet`) and
  `extraction.dll` - path to the built `extractor-net/bin/Release/net10.0/Extractor.dll`
- `publish.api_data_dir` - where the co-located API reads data + images
- `paths.data` - working dir for downloads/artifacts (needs ~30 GB per branch)
- *(optional)* `notifications.discord.webhook_url` for success/failure pings

Then create the state tables:

```bash
php orchestrator/bin/extractor db:migrate
```

**4. Steam login (one-time)**

The Docs + icon paks ship only with the game client, so downloads need an owning
account. Log in once interactively so `steamcmd` caches the session (later
non-interactive runs reuse it):

```bash
steamcmd +login <username>    # enter password + Steam Guard, then `quit`
```

**5. First run**

```bash
php orchestrator/bin/extractor run --branch=stable
```

**6. Schedule (hourly systemd timer)**

`systemd/sf-extractor@.service` is a **template** - copy the units, then edit the
three `CHANGE_ME`/placeholder lines in the installed service to match your host:

- `User=` - the account that owns the cached `steamcmd` session + .NET runtime
- `WorkingDirectory=` - absolute path to this repo's `orchestrator/`
- `ExecStart=` - the `php` binary path if it isn't `/usr/bin/php`

```bash
sudo cp systemd/sf-extractor@.service systemd/sf-extractor@.timer /etc/systemd/system/
sudoedit /etc/systemd/system/sf-extractor@.service   # set User=, WorkingDirectory=, php path
sudo systemctl daemon-reload
sudo systemctl enable --now sf-extractor@stable.timer   # instance name = branch
```

(You can instead edit the copies in `systemd/` before `cp` - either works.)
Only `stable` is scheduled by default; add experimental (disk permitting) with
`systemctl enable --now sf-extractor@experimental.timer`.

## Commands

```bash
php orchestrator/bin/extractor check                        # new build? (no credentials/download)
php orchestrator/bin/extractor run --branch=stable          # run one branch
php orchestrator/bin/extractor run --branch=all             # both branches (default)
php orchestrator/bin/extractor run --branch=stable --force  # ignore the "unchanged" check
php orchestrator/bin/extractor db:migrate                   # create/verify state tables
```

`check` needs no Steam credentials and works even before the DB exists (it just
skips the comparison). `run` requires the DB.

Operating the scheduled unit:

```bash
systemctl start sf-extractor@stable.service      # trigger a run now
journalctl -u sf-extractor@stable.service -e     # the last run's logs
systemctl list-timers 'sf-extractor@*'           # next scheduled run
```

Failures are written to `notifications.log_file` and (if configured) a Discord webhook.

## Credits

Built on [CUE4Parse](https://github.com/FabianFG/CUE4Parse),
[SkiaSharp](https://github.com/mono/SkiaSharp), and
[satisfactory-tools/docs-parser](https://github.com/SatisfactoryTools/DataUtils).
Not affiliated with Coffee Stain Studios.
