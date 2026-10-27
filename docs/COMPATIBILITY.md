# Compatibility

| Module version | OpenEMR versions | Notes |
|---|---|---|
| 1.0.0 – 1.0.5 | 7.0.0 – 7.0.3 | Developed and tested against 7.0.3. |
| 1.0.6 | 7.0.0 – 8.2.0 | Verified against 8.2.0. Support for 7.x is by fallback and has not been re-verified since the session change below. |

## Minimum version: 7.0.0

7.0 is the floor because the module depends on surfaces that are absent or
materially different before it:

- `OpenEMR\Events\PatientDemographics\RenderEvent` — the prescribing card.
- `OpenEMR\Events\Globals\GlobalsInitializedEvent` with
  `GlobalsService::createSection()` — module settings.
- `OpenEMR\Core\AbstractModuleActionListener` — the install/enable lifecycle.
- `OpenEMR\Services\PrescriptionService::insert()` — prescription write-back.
- SMART on FHIR app launch from patient context, added in 7.0.

## Verified against 7.0.3

These were checked against the installed source rather than assumed:

| Surface | Result |
|---|---|
| `RenderEvent::EVENT_SECTION_LIST_RENDER_AFTER` | present |
| `MenuEvent::MENU_UPDATE` | present |
| `GlobalsService::appendToSection($section, $key, GlobalSetting)` | takes a `GlobalSetting` object, not a tuple |
| `EventAuditLogger::getInstance()` | present (**not** `instance()`) |
| `SQLUpgradeService::setRenderOutputToScreen()` / `setThrowExceptionOnError()` | present |
| `PrescriptionService::insert()` required fields | `drug`, `patient_id` only |
| FHIR version | R4 (4.0.1) |

## Known differences by version

**Session access, 8.x.** OpenEMR moved session state behind a Symfony
`SessionInterface` obtained from `SessionWrapperFactory`, stored under an
`OpenEMR` namespace. `$_SESSION['authUserID']` and `$_SESSION['authUser']` are
empty on 8.2.0, and a write to `$_SESSION` is discarded when the session was
opened read_and_close.

Reading `$_SESSION` directly therefore failed silently: no user was identified,
so no per-provider credential was ever found, the facility fell through to the
lowest id regardless of the user's own record, and the audit trail recorded a
blank username for prescription writes.

`LogiCoy\ERx\Config\SessionContext` reads and writes through the wrapper when
it is present and falls back to `$_SESSION` when it is not, so both generations
work from one build. Never read `$_SESSION` directly anywhere in this module.

**Background services, 7.0 vs 8.x.** Both run the module's
`background_services` row (`Queue\BackgroundSync`) while anyone is signed in.
8.3.0 (verified) runs each due service in a separate process through
`bin/console`, with a lease lock; 7.0 runs it inside the signed-in user's own
request, through `library/ajax/execute_background_services.php`. So that a
background send is never audited under whoever happened to be signed in,
`BackgroundSync::run()` wraps the work in `SessionContext::runAsWorker()`,
which reads as a session with no user on both. Only the 8.3.0 path has been run.

When another version-specific workaround is added, record the OpenEMR version
that needs it and the version that fixes it here, and guard the code on the
version rather than on feature detection where possible.
