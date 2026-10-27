# Missing upstream hooks

Gaps in OpenEMR's event surface that this module works around. The rule is to
document the gap here rather than patch core.

## 1. No event for "prescription written by a third party"

**Wanted:** an event fired after a prescription row is created, so the module
could react to prescriptions written outside its own flow.

**Present:** nothing. `PrescriptionService::insert()` dispatches no event.

**Workaround:** the module only reflects prescriptions it knows about, keyed by
LogiCoy message id in `logicoy_erx_message_map`. A prescription written directly
in OpenEMR's native screen is invisible to us — accepted, since the module sits
alongside native prescribing rather than displacing it.

## 2. Patient Summary card rendering is echo-based

**Wanted:** a card registration API where a module returns a renderable and core
decides placement, ordering and markup.

**Present:** `RenderEvent::EVENT_SECTION_LIST_RENDER_AFTER` fires mid-page and
listeners `echo` directly.

**Workaround:** `Bootstrap::renderPrescribingCard()` echoes Bootstrap 4 markup
inline instead of rendering a Twig template — the Twig environment is not
guaranteed to be built at that point in the page. Revisit if core gains a
structured card API.

## 3. No cron registration API for modules

**Wanted:** a way for a module to declare a scheduled task that OpenEMR runs.

**Present:** nothing module-facing. Core cron jobs are hardcoded.

**Workaround:** the module adds its own row to core's `background_services`
table (`Queue\BackgroundSync`), the same table core and the bundled Weno module
use, and keeps it in step with Enable, Disable and Unregister from
`ModuleManagerListener`. Core runs due rows while anyone is signed in, or from
`bin/console background:services run` on cron. There is still no API for this,
so the row is written directly; its columns have been stable since 7.0, with
8.x adding `lock_expires_at`, which the module leaves to core.
`scripts/queue_worker.php` remains as a standalone CLI entry point. Either path
may run concurrently with the other and may be replayed, which is why the
worker assumes both.

## 4. `launch/encounter` is advertised but unsupported

**Wanted:** request `launch/encounter` and receive encounter context.

**Present:** on 7.0.x the SMART discovery document advertises
`context-ehr-encounter` in `capabilities`, but `launch/encounter` is absent from
`scopes_supported`, so requesting it fails the authorization.

**Workaround:** the scope is deliberately not requested
(`SmartLaunchHelper::REQUIRED_SCOPES`); the `encounter` field is read from the
token response opportunistically when the server chooses to send it.

## 5. Dynamic client registration quirks

**Wanted:** a spec-clean registration endpoint.

**Present:** on 7.0.x —

- a missing `jwks` is rejected with
  `Unexpected value for parameter "jwks": expecting "array", got "string"`
- `scopes_supported` in the discovery document is a nested array (`[[...]]`)
- no `registration_endpoint` is advertised at all

**Workaround:** `SmartLaunchHelper::buildRegistrationRequest()` always sends
`"jwks": {"keys": []}`, `flattenSupportedScopes()` flattens defensively, and the
registration path is hardcoded. All three are covered by tests so a future
OpenEMR fix shows up as a failing assumption rather than a silent break.

## 6. FHIR `MedicationRequest` is read-only

**Wanted:** write prescriptions over FHIR.

**Present:** only `GET /fhir/MedicationRequest` and
`GET /fhir/MedicationRequest/:uuid` are routed on 7.0.x.

**Workaround:** write-back goes through `PrescriptionService` in-process rather
than over FHIR. `OpenEmrPrescriptionGateway` is the only class that touches it,
so there is one place to change if core gains FHIR writes.

## 7. Module SQL discovery does not match the documented layout

**Wanted:** the layout in `CLAUDE.md` — `sql/database.sql` for install, numbered
migrations under `sql/upgrade/`.

**Present:** `InstallerController::makeButtonForSqlAction()` only offers the SQL
**Install** button when `sql/install.sql` exists alongside `version.php`, and
`getFilesForUpgrade()` scans `sql/` (not `sql/upgrade/`) for files matching
`^(\d+)_(\d+)_(\d+)-to-\d+_\d+_\d+_upgrade\.sql$`.

**Workaround:** the install file is named `sql/install.sql` so core's Install
button works as `INSTALL.md` describes. Numbered migrations stay in
`sql/upgrade/` per the project layout, which means **core's Upgrade button will
never fire for this module** — `ModuleManagerListener::upgrade_sql()` globs and
runs `sql/upgrade/*.sql` itself instead. If a future release wants core to drive
upgrades, the migrations have to move flat into `sql/` and match that regex
exactly.

## 8. The user-save events fire after the write, and carry no user id on create

**Wanted:** a hook that runs while a user is being saved, so a module can
validate its own fields and refuse the save, and that identifies the row.

**Present:** `UserCreatedEvent` and `UserUpdatedEvent` both fire *after*
`usergroup_admin.php` has committed the user, and both carry the raw `$_POST`.
`UserUpdatedEvent::getUserId()` reads `$_POST['id']`, which the edit form does
send; `UserCreatedEvent` has no id at all, because core does not read back the
row it just inserted.

**Workaround:** `ProviderProfileForm::resolveUserIdByUsername()` looks the new
user up by username — `UserCreatedEvent::getUsername()` normalizes core's
`rumple` field for us. And because there is nothing left to abort by the time we
run, `ProviderProfileInput` validates each field on its own and drops a bad one
rather than failing the whole profile; the rendered markup carries the matching
`type`, `pattern` and `max` constraints so this is the hand-edited-request case,
not the normal one. Anything dropped goes to the system log by field name only.

A POST with none of our fields is treated as "this form predates the module"
and leaves an existing profile untouched, rather than blanking it.

## 9. User-screen field injection is echo-based, same as the summary card

**Wanted:** a field registration API for the user screens.

**Present:** `UserEditRenderEvent::EVENT_USER_EDIT_RENDER_AFTER` fires inside a
`<td colspan="4">` that core sets aside inside its own `<form>`, and listeners
`echo` into it. Core's own TODO at that spot says as much.

**Workaround:** `ProviderProfileForm::render()` echoes a nested table matching
the surrounding column widths. Because the cell is inside the form, the fields
post with the rest of the user record and no core change is needed. Field names
are prefixed `logicoy_erx_` so nothing can collide with a core POST key.

## 10. The user screens show only part of the `users` row

**Wanted:** one screen where an admin can complete a provider record.

**Present:** `usergroup_admin_add.php` and `user_admin.php` between them expose
name, NPI, DEA number, state licence and email, but not `phonew1`, `street`,
`city`, `state` or `zip` — all of which are columns on the same `users` row.
Those are only editable under Administration → Address Book
(`addrbook_edit.php`). `users` has no sex or gender column at all.

**Workaround:** `UserContactStore` surfaces the five contact columns inside the
eRx block on the user screens and writes them back to those same core columns,
so a prescriber has one address rather than two that can drift. Gender has
nowhere in core to live, so it is the one contact-shaped field the module stores
itself, in `logicoy_erx_provider_profile`.

This is the only place the module writes `users`. It sets existing columns to
values an admin typed on a screen they already had permission to use; no core
file and no core schema is touched.
