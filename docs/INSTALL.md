# Installing LogiCoy eRx

For practice administrators. You will need OpenEMR administrator access and the
credentials pack supplied by LogiCoy.

## Requirements

- OpenEMR **7.0.0 or later**
- PHP 8.1 or later
- Outbound HTTPS from the OpenEMR server to the LogiCoy backend

## 1. Place the module

If your server has git or composer, the module can be placed the same way as
any other OpenEMR custom module. Both land it at
`interface/modules/custom_modules/oe-module-logicoy-erx`; skip to step 2
afterwards.

```bash
cd /path/to/openemr/interface/modules/custom_modules
git clone https://github.com/Logicoy/oe-module-logicoy-erx.git
```

or, from the OpenEMR root:

```bash
composer require logicoy/oe-module-logicoy-erx
```

Otherwise, LogiCoy supplies a single installer file, `install-oe-module-logicoy-erx-<version>.sh`.
It carries the whole module inside it — there is nothing else to download.

Copy it to the OpenEMR server and run it as root:

```bash
sudo sh install-oe-module-logicoy-erx-1.0.6.sh
```

It finds the OpenEMR directory, checks the OpenEMR and PHP versions, places the
module, and gives it the ownership the web server needs. Pass
`--openemr-root=/path/to/openemr` if your installation is somewhere it does not
look, and `--dry-run` first if you want to see what it will do.

### If OpenEMR runs in Docker

The module has to land on the container's filesystem, not the host's. Run the
installer on the host anyway — when there is no OpenEMR on the host it looks for
a container running one, and offers to install into it:

```
OpenEMR is not installed on this machine, but the container 'openemr' looks
like it runs it.
Install into that container? [y/N]
```

Name the container yourself if more than one is running, or if it is named
something the installer does not recognise:

```bash
sudo sh install-oe-module-logicoy-erx-1.0.6.sh --container=openemr
```

Either way the installer copies itself in and runs there, so the result is the
same as running it inside the container by hand.

A module installed into a container lives in the container's writable layer and
is lost on the next image update or `docker compose up --force-recreate` —
exactly the same trap as a key file on a path. Reinstall it after an update, or
bind-mount the module directory in your compose file:

```yaml
    volumes:
      - ./oe-module-logicoy-erx:/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-logicoy-erx
```

If the module directory is already a bind mount, the installer writes the module
*through* the mount, into the directory on the host — so it survives container
rebuilds. Bind-mounting an empty directory there is the supported way to make a
containerised install persistent, and needs no special handling.

Replacing a bind-mounted directory that already has files in it asks for
confirmation first, and is refused outright on an unattended run. The files
being replaced live outside the container and are often somebody's working copy.

Upgrading over an existing installation archives the old one to the current
directory first and leaves the module's tables and credentials untouched.

After an upgrade from a build older than 2026-10-19, prescriptions that were
sent through the LogiCoy screen before it do not yet appear on the patient's
**Prescriptions** card. The module puts them there once, by itself, the next
time it is enabled or upgraded in Manage Modules (Disable, then Enable, does
it). To run it by hand, or to see what it would do first:

```bash
php scripts/backfill_prescriptions.php --dry-run
php scripts/backfill_prescriptions.php
```

Run it from the module directory as the web server user. It is safe to repeat:
a script already on the chart is never written twice. Only the drug name,
patient, prescriber and date were recorded locally, so backfilled rows carry no
directions or quantity.

`sh install-oe-module-logicoy-erx-1.0.6.sh --help` lists every option.

### Installing by hand instead

If your site deploys through configuration management, unpack the module
without touching OpenEMR:

```bash
sh install-oe-module-logicoy-erx-1.0.6.sh --extract-only=/tmp/logicoy
```

and place the resulting directory yourself at:

```
interface/modules/custom_modules/oe-module-logicoy-erx
```

The directory name matters — it is how OpenEMR finds the module. It must be
readable by the web server user, and no more than that: directories `755`,
files `644`.

## 2. Register and install

1. Go to **Modules → Manage Modules**. `Modules` is a top-level menu item, not
   part of the Admin menu; it requires the `menus` / `modle` permission to be
   visible at all.
2. Find **LogiCoy eRx** in the unregistered list and click **Register**.
3. Click **Install** — this creates the module's tables. Installing is safe to
   repeat; it will not touch tables that already exist.
4. Click **Enable**.

## 3. Configure the connection

Go to **Administration → Globals → LogiCoy eRx** and set:

| Setting | Value |
|---|---|
| Enable LogiCoy eRx | On |
| Backend base URL | Supplied by LogiCoy, e.g. `https://eprescribing.logicoy.com/erxapi` |
| Backend token URL | Leave blank unless LogiCoy tells you otherwise |
| Write to the OpenEMR prescriptions table | On |
| Open LogiCoy eRx from OpenEMR's prescription buttons | On |
| Enable inbound polling | On |
| Ask the pharmacy to report fill status | *All fill statuses* (the default). After each prescription is sent, the pharmacy is asked to report how it dispenses it; the reports appear as the pharmacy status on the sent prescription. Choose *Do not ask* to turn this off. |

Credentials are **not** stored here. Globals are readable by any administrator
and are included in backups, so the private key lives encrypted in the module's
own credential table instead.

## 4. Load your credentials

LogiCoy supplies, per practice: a client id and an eRx client registration
uuid. The private key (PEM) is generated by the practice in the
**LogiCoy Vendor portal** and downloaded from there. The eRx practice /
location / clinician identifiers are issued when the practice registers from
the Settings screen (step 3 there).

Two ways to load them. The screen is preferred — it always encrypts as the web
server user, which removes the most common setup mistake.

### Option A — the credentials screen (recommended)

**Miscellaneous → LogiCoy eRx → Settings tab** (administrators only), or
**Modules → Manage Modules → LogiCoy eRx → the config icon**; both open the
same screen.

Choose the facility, then supply the key one of three ways and **Save
credential**:

1. **Upload** the `.pem` / `.key` file
2. **Paste** the PEM into the text box
3. **Reference a key already on the server** by absolute path, for keys placed
   by configuration management

Uploaded and pasted keys are written to
`sites/<site>/documents/certificates/logicoy_erx_<scope>_<facility>.key` at mode
`0600` — the same directory OpenEMR keeps its own OAuth private key in, which
Apache denies over HTTP. Only the path is stored in the database.

**On Docker, always upload — do not use a path.** Only mounted volumes survive
`docker compose up --force-recreate` or an image update. `sites/` is a named
volume in the standard OpenEMR compose file, so an uploaded key persists; a path
like `/etc/logicoy/partner_private.key` lives in the container's writable layer
and is deleted on the next rebuild, with prescribing failing afterwards for no
obvious reason. The module detects this and warns on the credentials screen.

If you must reference a path under Docker, bind-mount it explicitly:

```yaml
    volumes:
      - /host/secure/logicoy:/etc/logicoy:ro
```

A referenced path is checked before it is accepted. It is refused if the file is
missing, unreadable by the web server user, readable by other users on the
server, not an RSA private key, or **anywhere the web server could serve it**.
That last check is the important one — a key under the document root is one
request away from being downloaded.

The client id and the eRx UUIDs can be set here, but it is usually easier to set
them once under **Administration → Globals → LogiCoy eRx** — anything left blank
here falls back to those.

The key is read straight from the upload, encrypted with OpenEMR's own key
material, and stored. It is never written into the module directory, never
logged, and never shown again — the list only reports whether a key is present.

- To rotate a client id without touching the key, leave the file field empty.
- To rotate the key, upload a new file.
- To retire a credential, use **Deactivate**.

Requires the `admin` / `super` permission.

> **Why is the key not a global?** `globals.gl_value` is `varchar(255)`. A
> PKCS#8 RSA-2048 key is about 1,700 characters — 1,624 even with the armor and
> newlines stripped — and base64 encoding makes it larger, not smaller, because
> a PEM is already base64. MySQL rejects the insert outright with
> "Data too long for column". The key is stored in `logicoy_erx_credentials`,
> whose column is `LONGTEXT`, encrypted with `CryptoGen`.

### Option B — the command line

Useful for scripted installs. Must run as the web server user, or the key is
encrypted with material the web server cannot read:

```bash
cd /path/to/openemr/interface/modules/custom_modules/oe-module-logicoy-erx

sudo -u www-data php scripts/load_credential.php \
    --facility=<facility id> \
    --client-id=<client id> \
    --key=/secure/path/partner_private.key \
    --practice-uuid=<practice uuid> \
    --location-uuid=<location uuid> \
    --clinician-uuid=<clinician uuid>
```

A prescriber has one clinician identifier. The backend names it
`erxClinicianUserPKUuid` when registering and `erxClinicianUserUuid` on the
prescription draft endpoints; the module fills in whichever side is left blank,
so passing `--clinician-uuid` alone is enough. Pass `--clinician-pk-uuid` as
well only if LogiCoy issued you two genuinely different values.

OpenEMR refuses to run this as root. That is deliberate — root would create key
files the web server cannot read.

List what is loaded (never prints key material):

```bash
sudo -u www-data php scripts/load_credential.php --list
```

A prescriber may carry their own client id via either route — choose the
provider scope. When a prescriber has their own credential it is used; otherwise
the practice credential is.

## 5. Set up each prescriber

**Administration → Users →** the prescriber (or **Add User**). Below the core
fields there is a **LogiCoy eRx** block:

| Field | Required to register? | Notes |
|---|---|---|
| Date of Birth | yes | LogiCoy refuses a prescriber under 18. |
| Gender | yes | Male, Female or Other — LogiCoy accepts nothing else. |
| eRx Prescribing Type | — | **eRx** or **EPCS**. Defaults to eRx. |
| DEA Expiration Date | EPCS only | |
| DEA State | EPCS only | Two letter code, from OpenEMR's own state list. |
| SSN (last 4) | EPCS only | Four digits, encrypted at rest. |
| Phone, Street, City, State, ZIP | yes | |

Also required, from the core fields higher up the same screen: **NPI**, **Email**
and — for EPCS — **DEA number**.

The eRx fields are stored in the module's own table. Gender is there because
OpenEMR's user record has no field for it. **Phone and address are not** — those
write straight into the same `users` columns OpenEMR's Address Book uses, so
there is only ever one address for a user. They appear here because neither user
screen shows them and a prescriber cannot be registered without them.

Everything above is sent to LogiCoy when the prescriber is registered on the
credentials screen.

- **eRx** registers the prescriber as non-EPCS. Controlled substances are
  blocked at the backend.
- **EPCS** additionally needs the **DEA number** from the core field above,
  plus the three EPCS-only fields. Registration is refused with a message
  naming what is missing rather than half-registering the prescriber.
- The SSN fragment is never shown again once saved — the box shows a placeholder
  and leaving it blank keeps what is stored. Tick **Remove stored SSN** to clear
  it.

Changing the prescribing type after a prescriber has been registered means
re-registering them on the credentials screen; the type is sent at registration.

Requires the `admin` / `users` permission, the same as the rest of that screen.

### 5a. ID.me identity verification (EPCS prescribers)

LogiCoy will not accept a controlled substance prescription until the
prescriber's identity has been proofed through **ID.me**. This is done by the
prescriber, signed in as themselves — an administrator cannot do it for them,
because ID.me verifies the person at the keyboard.

After the prescriber has been registered (step 4, "Register with LogiCoy"),
the **ID.me identity verification (EPCS)** table under the registration steps
lists each registered prescriber with a **Verify with ID.me** button:

1. With the prescriber at the keyboard, enter the email address of their own
   ID.me account (each prescriber needs their own; LogiCoy refuses an email
   already used by another prescriber) and press **Verify with ID.me**.
2. ID.me opens in a new tab (it will not load inside OpenEMR's frames). The
   prescriber signs in and completes the verification there.
3. ID.me sends that tab back to a small **Back from ID.me** page in OpenEMR.
   Press **Check the result** there: it opens the LogiCoy eRx screen, which
   confirms the result with LogiCoy and shows **Verified** with an expiry
   date (if OpenEMR asks for a sign-in first, sign in and press **Check
   status** on the row). Or close the tab and press **Check status** on the
   row, or reload Manage Modules, to see it there too.

Prescribers can also do the same themselves, signed in as themselves, under
**Modules → LogiCoy eRx → ID.me Verification**.

The same row has **Update with LogiCoy**. Press it after changing a registered
prescriber's details under Administration, Users: it sends their DEA number,
expiry and state, date of birth, phone, address and state license again, and
asks for the controlled substance service level when the prescriber is set to
EPCS. It also asks for the fill-status reporting service level
(`RX_FILL_INDICATOR_CHANGE`); a prescriber registered before module 1.0.10
needs it pressed once, or their sent prescriptions will show *Fill reports not
requested*.

A prescriber first registered as ERX stays ERX at LogiCoy until **Upgrade to
EPCS** is pressed on their row. Before pressing it, set the prescriber to EPCS
under Administration, Users with the DEA number, expiry, state and SSN last 4
filled in, and enter their ID.me email on the row. LogiCoy validates the DEA,
registers them for controlled substances, and opens ID.me in a new tab for
identity proofing; when it returns, the screen shows the upgrade result. **EPCS
status** asks LogiCoy where the upgrade stands at any time. The upgrade cannot
be undone. Your LogiCoy client application must itself be EPCS-enabled, or the
request is refused.

The return trip uses the **Site Address** global (`site_addr_oath`), so that
must be an address the prescriber's browser can reach. If the screen comes back
without a result, press **Check status**.

The prescribing screen warns an EPCS prescriber whose verification is missing,
expired, or within 30 days of expiring. The credentials screen lists every
registered prescriber's standing under the registration steps.

In LogiCoy's **staging** environment, ID.me is not live: use the pre-verified
prescriber credentials LogiCoy supplies instead of a real ID.me account.

#### Two-factor authentication before sending a controlled substance

Verification proves who the prescriber is, once a year. Before LogiCoy accepts
each **controlled substance** prescription it also needs a second factor from
ID.me for the current session — a code from the ID.me authenticator app, a
text or an email, or a push notification to the ID.me app. The session lasts
for the time ID.me gives (shown on screen) and is then done again.

Nothing is asked while drafting. When the prescriber presses **Send** on a
controlled substance draft without a current session, a window opens on the
prescribing screen:

1. Choose one of the methods set up on the ID.me account. (If none are listed,
   add one at id.me first.)
2. Enter the code ID.me sends, or accept the push notification in the ID.me
   app — the window waits and updates itself.
3. The moment ID.me accepts, the prescription is sent, and a banner shows how
   long the session has left. Further controlled substances in that time are
   sent without being asked again.

The same step can be done ahead of time on the **ID.me Verification** screen,
under the facility that shows **Verified**. A prescription for an ordinary
drug is never asked. A prescriber registered at more than one facility does
this once per facility, because LogiCoy keys the clinician on practice and
location.

If the card says the MFA session table is missing, the module was upgraded
without its schema step: **Disable** and **Enable** it in Manage Modules.

## 6. Background sync (automatic)

Prescription traffic is queued, never sent inline, so a slow backend can never
hold up a clinician's screen. There is nothing to schedule: enabling the module
in Manage Modules adds an OpenEMR background service, **LogiCoy eRx sync**, that
runs every five minutes. Disabling the module switches it off; unregistering
removes it.

OpenEMR runs background services while anyone is signed in — the main screen
asks for them every minute — so the sync keeps pace through the working day
with no setup. The settings screen shows **Background sync on** in *Receiving
updates from LogiCoy*, with when the next run is due.

Each run polls every facility that has an active practice credential, so a new
facility needs nothing beyond its credential.

If the screen says **Background sync not set up**, the module was upgraded by
replacing its files without the Manage Modules step: **Disable** and **Enable**
it, or open the settings screen while the module is enabled, which adds it.

Background services do not run when the server sets
`OPENEMR__NO_BACKGROUND_TASKS`. In that case, or to keep collecting pharmacy
messages overnight while nobody is signed in, run OpenEMR's own runner from
cron. It runs every due background service, this one included:

```
* * * * * php /path/to/openemr/bin/console background:services run
```

On OpenEMR 7.0, which has no `bin/console` command for this, use
`php /path/to/openemr/library/ajax/execute_background_services.php` instead. Run
either as the web server user, like any OpenEMR cron job.

### Optional: the module's own worker

`scripts/queue_worker.php` does the same work for one facility and can still be
scheduled directly. It is safe to run alongside the background sync, safe to run
concurrently and safe to replay — if both fire at once, no prescription is sent
or written twice.

```
*/5 * * * * php /path/to/openemr/interface/modules/custom_modules/oe-module-logicoy-erx/scripts/queue_worker.php --facility=1
```

Run it as the web server user (`apache`, `www-data`), from that user's crontab
or with `sudo -u apache`. The private key is decrypted with OpenEMR's own key
material, which only that user can read. Add `--site=NAME` for a site other
than `default`.

**OpenEMR in Docker.** The official image does not run cron, so schedule the
worker on the host and run it inside the container:

```
*/5 * * * * docker exec -u apache <openemr-container> php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-logicoy-erx/scripts/queue_worker.php --facility=1
```

On a host without cron (Amazon Linux 2023, for example), a systemd timer that
runs the same `docker exec` command every five minutes does the same job.

To try it once by hand, run the same command without the schedule. It exits 0
on success and 1 on failure; details go to OpenEMR's system log.

Each run does two things:

- **Sends what prescribers queued** — cancel requests, and responses to
  pharmacy renewal and change requests. These show as *queued* on the screen
  until the worker has sent them.
- **Collects pharmacy notifications** for that facility — renewal, change and
  new prescription requests (they appear in **Miscellaneous → LogiCoy eRx →
  Inbox** and on the patient's card), cancel answers, fill events and delivery
  confirmations. A new prescription request names the patient as the
  pharmacy knows them; when they have no chart here, the inbox offers to add
  them to OpenEMR or link an existing chart before the request is answered.

The practice credential for the facility must carry the client registration
uuid LogiCoy issued; without it, notifications cannot be collected and the
worker logs an error. Every five minutes is a sensible interval: a pharmacy
waiting on a renewal sees the answer no sooner than the next run.

## 6a. Receive pushed notifications (optional)

If the LogiCoy backend can reach this OpenEMR site over HTTPS from the internet,
it can push pharmacy notifications as they happen instead of waiting for the
next worker run. Renewal and change requests then reach the inbox in seconds.

1. Open **Miscellaneous → LogiCoy eRx → Settings tab** (administrators only),
   or **Modules → Manage Modules → LogiCoy eRx → the config icon**; both open
   the same screen.
2. Under **Receiving updates from LogiCoy**, turn on **Accept pushed notifications
   (webhook)** and save.
3. Under **Webhook details for LogiCoy** in the same card, click **Generate webhook secret**. Copy it straight
   away — it is shown once and stored encrypted.
4. Give LogiCoy the **Webhook URL** shown in that card and the secret. They send
   the secret as `Authorization: Bearer <secret>`; the
   `X-LogiCoy-Webhook-Secret` header is also accepted. One URL takes every
   event; LogiCoy appends its own event path to it
   (`webhook.php/rx/renewal-request`), which the module reads. If a URL per
   event is registered instead, append `&event=renewal-request` (or
   `change-request`, `cancel-response`, `fill-status`, `history-response`,
   `pharmacy-initiated-new-rx-request`, `new-rx-notify-pending-verification`)
   — use `?event=` when the URL has no query string yet.

The URL is built from **Site Address** (`site_addr_oath`) in Globals. If it
shows `localhost`, set Site Address to the public HTTPS address first.

Keep the background sync running even with pushes on: it still sends everything
prescribers queue (cancel requests, renewal and change responses), and its
poll catches anything a push missed. Overlapping is safe — a notification is
recorded once however it arrives. **Replace webhook secret** cuts off the old
secret immediately, so give LogiCoy the new one at the same time.

| Response | Meaning |
|---|---|
| 200 | Accepted, including repeats of notifications already recorded |
| 400 | Malformed body, or a client registration this site does not hold |
| 401 | Missing or wrong secret |
| 404 | Webhook is off (or the module is) |
| 500 | Some notifications could not be processed; redelivery is safe |
| 503 | Webhook is on but no secret has been generated |

## 7. Verify

1. Open a patient chart. A **LogiCoy eRx** card appears on Patient Summary with
   an **Open prescribing** button.
2. If the card is missing, check in order:
   - the module is enabled in Manage Modules
   - **Enable LogiCoy eRx** is on in Globals
   - the logged-in user has the same prescribing permission as core Rx
     (`patients` / `rx`)
3. Open **Miscellaneous, LogiCoy eRx** and check the **Log** tab for queue activity.
4. After the background sync has run, the **Inbox** tab there lists any pharmacy
   requests waiting on a prescriber. Administrators also get a **Settings**
   tab, the same screen Manage Modules opens.

## Uninstalling

Disabling or unregistering the module leaves its tables in place. Prescription
records are audited clinical data, so removing them is a deliberate manual step
— ask LogiCoy support before dropping anything.
