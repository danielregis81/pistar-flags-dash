**English** | [Portugues](README.pt-BR.md)

# pistar-flags-dash

Shows the country flag, the operator name and the country code (with the full
country name on mouse-over) in the *Last Heard* list of the **official
Pi-Star** dashboard - light enough to run on a Raspberry Pi Zero W.

![Last Heard example with flags, names and country codes](docs/screenshot.png)

Each row gets the country flag, the operator name and a 3-letter country code
(e.g. `(BRA)`, `(USA)`, `(IND)`). Hovering over the code shows the full country
name - in the dashboard's language.

---

## Why this exists

I run my hotspot on a Raspberry Pi Zero W (ARMv6, single-core). I wanted a more
informative dashboard - country flag, name of who is talking - like the
"souped-up" dashboards you see around.

The obvious choice would be WPSD (W0CHP-PiStar-Dash), which is excellent and has
all of this built in. But the WPSD author himself states clearly that it was NOT
made for weak hardware like the Pi Zero W - it runs slow and heavy on that kind
of board. And it is true: WPSD computes and shows a lot of things in real time,
which is too much for a Pi Zero.

I did not want the whole package. I only wanted three things: flag, name and
country. So instead of replacing my hardware or living with a slow dashboard, I
wrote this minimal patch: it adds exactly that information to the OFFICIAL
Pi-Star dashboard, without the weight of a full fork.

In short: if you are in the same situation - official Pi-Star, modest hardware
(Pi Zero W and similar), and you just want flag + name + country without
replacing the whole dashboard - this project is for you.

> WARNING: this is for the OFFICIAL Pi-Star only. If you already use WPSD or
> another fork, you do not need this (they already have this feature). The
> installer detects forks and aborts without touching anything.

---

## What it does

- Country flag next to the callsign (derived from the prefix using the ITU
  prefix table - worldwide coverage, more than 230 countries and territories).
- Operator name (read from `DMRIds.dat`, the same database Pi-Star already
  downloads).
- 3-letter country code in place of the old "(GPS)" link - which pointed to
  aprs.fi and, in practice, was not very useful. E.g. `(BRA)`, `(USA)`.
- Tooltip with the full country name, in the dashboard's language: with Pi-Star
  in English it shows "Brazil"; in Portuguese, "Brasil".
- Makes the *Time* column a bit narrower, leaving more room for the callsign.
- Adds a credit line to the dashboard footer:
  `Flags, names & country codes: pistar-flags-dash by PP5RD (...)`.

### Why it is light (it matters on a Pi Zero)

- The flag is a static SVG image served by the web server itself - practically
  zero CPU cost.
- `DMRIds.dat` (~6-7 MB) is read only once, and the callsign -> name pairs are
  kept in a disk cache that is rebuilt only when the file changes. On the 1.5 s
  refreshes the cache is read - not the whole file.
- Everything else (country, code, tooltip) is resolved in memory, with no
  network access.

In other words: none of the heavy processing that makes full forks slow on a
Pi Zero.

---

## Installation

### 1. First of all: back up your configuration

On the dashboard, go to **Configuration -> Backup/Restore** and download the
backup (a `.zip` file). Keep it on your computer.

This patch does not touch the hotspot configuration, but any change to Pi-Star
deserves a backup first. If something goes wrong, restore the zip on the same
page. Note: Pi-Star itself warns that system/dashboard passwords are NOT backed
up (WiFi and DMR network passwords are).

### 2. Install

On your hotspot, over SSH:

```bash
cd /tmp
git clone https://github.com/danielregis81/pistar-flags-dash.git
cd pistar-flags-dash
sudo ./install.sh
```

Then press Ctrl+F5 on the dashboard (hard refresh to clear the browser cache).

**Why `cd /tmp` first?** The Pi-Star disk is read-only, including your home
folder (`/home/pi-star`). If you run `git clone` there you will get
`Read-only file system`. `/tmp` lives in memory and is writable, so that is
where the clone should go. The installer itself takes care of enabling writes
to the disk only during the installation.

**Note:** `/tmp` is wiped when the Pi reboots. The installation itself stays
(it is written to the disk), but the cloned folder is gone. To uninstall or
update after a reboot, run `cd /tmp` and `git clone` again.

### Commands

```bash
sudo ./install.sh              # install (or update)
sudo ./install.sh --status     # show the current state
sudo ./install.sh --uninstall  # remove and restore the original dashboard
```

The installer takes care of everything:
- detects the dashboard and aborts if it is a fork (WPSD etc.);
- remounts the filesystem read-write (`rpi-rw` if available, otherwise
  `mount -o remount,rw /`) and back to read-only at the end - even on error or
  if the installation is interrupted (Ctrl+C, SSH dropped);
- backs up the original `lh.php` before any change;
- applies the patch non-destructively (it only injects what is needed and does
  not replace the file) and checks the syntax with `php -l`;
- installs the automatic post-update re-apply (see below).

### What is installed and where

| Where | What |
|-------|------|
| `/var/www/dashboard/mmdvmhost/lh.php` | gets the patch (the original is kept as `lh.php.bak-flags`) |
| `/var/www/dashboard/mmdvmhost/lh_enrich.php` | country, name and tooltip functions |
| `/var/www/dashboard/images/flags/` | SVG flags |
| `/usr/local/share/lh-flags-patch/` | the project's own folder: copy of the files and the re-apply script |
| `/etc/cron.hourly/zz-pistar-lh-reapply` | hourly re-apply trigger |

Nothing is written to the hotspot configuration (`/etc/mmdvmhost`,
`/etc/dmrgateway` etc.). The re-apply script lives in the project's own folder,
outside the folders Pi-Star manages with git (`/usr/local/sbin`,
`/usr/local/bin`). Older versions of this project put that script in
`/usr/local/sbin`; the current installer removes it from there automatically.

---

## Survives Pi-Star updates

The Pi-Star dashboard is a git repository, and a `pistar-update` may revert
`lh.php`. So that this does not undo the customization, the project installs a
`cron.hourly` job that re-applies the patch by itself if it detects that it is
gone. When there is nothing to do, it exits right away without touching the
disk.

---

## Uninstall

```bash
sudo ./install.sh --uninstall
```

Restores the original `lh.php` (from the backup) and removes the flags,
`lh_enrich.php`, the re-apply script and the cron job. Press Ctrl+F5 afterwards.

---

## Limitations (to be honest)

- The name comes from `DMRIds.dat` (DMR database). Callsigns that are not in
  that database show only the flag - no name. No error, the data is just not
  there.
- Flag/country depend on the callsign prefix (ITU table). It covers the vast
  majority, but if an unmapped prefix shows up the row has no flag (nothing
  breaks). PRs with new prefixes are welcome.
- Designed and tested on the official Pi-Star (v4.2.6, Pi Zero W). I have not
  tested every version/fork - and on forks it does not even install, on purpose.

---

## Layout

```
pistar-flags-dash/
|- install.sh               # install / uninstall / status
|- lib/patch-lh.sh          # non-destructive, idempotent lh.php patch
|- src/
|  |- lh_enrich.php         # functions: country (ITU), name (DMRIds+cache), tooltip
|  \- flags/                # SVG flags (ISO-3166-1 alpha-2) + INDEX
|- pistar-lh-reapply        # post-update re-apply
|- cron/zz-pistar-lh-reapply # cron.hourly trigger
|- docs/screenshot.png
|- README.md                # this file (English)
|- README.pt-BR.md          # Portuguese version
\- LICENSE                  # GPL-3.0
```

---

## Credits

- Andy Taylor (MW0MWZ) - creator of Pi-Star, who made all of this possible.
- Hampus Borgos - [country-flags](https://github.com/hampusborgos/country-flags),
  the SVG flags (public domain).
- The idea of a flag per callsign is inspired by Pi-Star forks (such as
  WPSD/W0CHP) and by projects like `xlxd`.

This is NOT an official Pi-Star project. Use at your own risk.

## License

GPL-3.0 - see [LICENSE](LICENSE). Pi-Star is GPL; this is a derivative work and
follows the same license.
