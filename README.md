# OpenEMR LogiCoy eRx

An OpenEMR custom module that connects a practice's OpenEMR installation to the
LogiCoy eRx network.

**Module Manager name:** LogiCoy eRx API  
**Package version:** 1.0.9 (`version.php`)  
**Install directory name:** `oe-module-logicoy-erx`  
**Namespace:** `LogiCoy\ERx`  
**Author:** LogiCoy

## Compatibility

| Target | Status |
|--------|--------|
| **OpenEMR 7.0.x** (PHP 8.1+) | Supported |
| **OpenEMR 8.x** | Verified against 8.2.0; see [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md) |

- PHP **8.1+**
- Outbound HTTPS from the OpenEMR server to the LogiCoy backend
- LogiCoy credentials, configured in the module's setup screen (never stored in
  this repository)

## Installation

Pick one of the three ways to place the module, then finish in Module Manager.

**Git clone**

```bash
cd /path/to/openemr/interface/modules/custom_modules
git clone https://github.com/Logicoy/oe-module-logicoy-erx.git
```

**Composer** (from the OpenEMR root)

```bash
composer require logicoy/oe-module-logicoy-erx
```

**Installer script** (for practices without git or composer)

LogiCoy supplies a single self-extracting file,
`install-oe-module-logicoy-erx-<version>.sh`, built by
`scripts/build_installer.sh`. Run it as root on the OpenEMR server; it finds
the OpenEMR directory, places the module and sets ownership.

Then in OpenEMR: **Modules → Manage Modules** → find **LogiCoy eRx API** in the
unregistered list → **Register** → **Install** (creates the module's tables)
→ **Enable** → open the module's config icon to load credentials.

The directory name must stay `oe-module-logicoy-erx`; it is how OpenEMR finds
the module. No `composer install` is needed at runtime: the module has no
dependencies beyond what OpenEMR ships.

[docs/INSTALL.md](docs/INSTALL.md) is the full administrator guide, covering
Docker installs, configuration, credentials, prescriber setup and background
sync.

### Database

- `sql/install.sql` — initial schema, run by the **Install** button. Guarded
  with `#IfNotTable`, so repeating it is safe.
- `sql/upgrade/*_upgrade.sql` — versioned migrations, applied by
  `ModuleManagerListener` on upgrade.

## What this module does

- Adds a prescribing entry point in patient context, and opens it from
  OpenEMR's own prescription buttons (the Eye Exam Impression/Plan eRx button,
  the Prescriptions card); the native Rx list stays one click away, and a
  setting turns the takeover off
- Stores per-practice and per-provider credentials, with the private key
  encrypted at rest
- Reflects prescriptions into OpenEMR's native `prescriptions` table
- Queues and syncs prescription traffic with the LogiCoy backend
- Surfaces a prescription log for support

## What this module does not do

It is a thin client and contains no clinical logic. Drug lookup, interaction
checking, NCPDP SCRIPT construction, EPCS signing and Surescripts routing all
live behind the network boundary in the LogiCoy backend. That boundary is not
negotiable: OpenEMR is GPL v3 and this module is a derivative work distributed
under GPL, so proprietary logic stays in a separate process.

## Architecture

```
Patient Summary card  ──▶ public/launch.php ──▶ SMART EHR launch into the LogiCoy UI
                                                (OpenEMR mints the launch token)

Prescription written  ──▶ logicoy_erx_queue ──▶ scripts/queue_worker.php ──▶ backend
                                                          │
Backend polled for                                        ▼
refills / RxChange    ──▶ logicoy_erx_queue ──▶ PrescriptionWriter ──▶ prescriptions
                                                (guarded by logicoy_erx_message_map)

Prescribing screen    ──▶ public/api.php rx-send ──▶ backend rxnew/send
Send pressed                      │
                                  ▼
                         PrescriptionWriter ──▶ prescriptions (the chart's
                         (keyed on the medication uuid)       Prescriptions card)

Backend pushes to     ──▶ public/webhook.php ──▶ NotificationPoller::process()
public/webhook.php        (shared secret)        ──▶ logicoy_erx_notification
                                                 (same path the background poll uses)
```

Inbound notifications arrive one of two ways, and both can run at once:

- **Pushed** — the LogiCoy backend posts them to `public/webhook.php`,
  authenticated by a shared secret generated on the module screen. Off by
  default; needs a URL the backend can reach over HTTPS.
- **Polled** — an OpenEMR background service (`Queue\BackgroundSync`) reads
  `/v1/notifications/get` every five minutes, for installs behind a firewall
  with no inbound path. Nothing to schedule; `scripts/queue_worker.php` does the
  same from cron for practices that want it.

Both feed `NotificationPoller::process()`, keyed on the LogiCoy notification
uuid, so a notification that is pushed, redelivered and polled is still
recorded and followed up once. Outbound traffic always goes through the queue
and the worker. Nothing blocks a UI request on a backend call.

### Idempotency

OpenEMR's cron is unreliable on shared hosting and will replay. Every message
carries a LogiCoy message id, which is the unique key on both
`logicoy_erx_queue` and `logicoy_erx_message_map`. A duplicate poll re-enqueues
nothing and re-writes nothing. This is covered by `tests/ReplayTest.php`.

## Layout

```
src/
  Bootstrap.php              events, menus, globals
  Client/                    HTTP client, OAuth2 token provider, payload rules
  Queue/                     queue repository, worker, background service
  Prescription/              write-back adapter and the replay guard
  Smart/                     SMART launch helper
  Config/                    globals, encrypted credential storage,
                             per-prescriber eRx profile + user-screen fields
public/                      launch entry point and prescription log
sql/install.sql              install schema (name required by core)
sql/upgrade/                 numbered migrations; never edit database.sql
scripts/load_credential.php  credential loader CLI
scripts/background_service.php  OpenEMR background service entry point
scripts/queue_worker.php     optional cron entry point
scripts/cli_bootstrap.php    shared CLI bootstrap (site + namespace)
scripts/autoload.php         module PSR-4 for entry points outside the loader
docs/                        INSTALL, COMPATIBILITY, HOOKS
```

## Dependencies

None at runtime beyond what OpenEMR already ships. Every package would be
distributed to client sites and has to be GPL v3 compatible, so the module uses
core's Symfony EventDispatcher, `QueryUtils`, `CryptoGen` and `SystemLogger`
rather than adding its own.

`phpunit/phpunit` is a development dependency only and is not shipped.

## Tests

```bash
composer install
./dev.sh check          # PSR-12 + unit tests
```

Unit tests never touch a database and never call the LogiCoy backend. All
fixtures are synthetic — no PHI, ever.

[TESTING.md](TESTING.md) is the full walkthrough, split into what can be tested
today and what needs the LogiCoy credential. `./dev.sh` wraps the container
commands; run it with no arguments to see what it does.

## Security

- Do not commit backend credentials, private keys or client secrets; they are
  configured only in the module's credentials screen and stored encrypted.
- Every entry point stays behind OpenEMR's authentication, CSRF and ACL
  checks. Prescribing surfaces check the same ACL as core Rx.

## License

GNU General Public License v3.0 or later (GPL-3.0-or-later). See
[LICENSE](LICENSE).

## Support

https://github.com/Logicoy/oe-module-logicoy-erx/issues
