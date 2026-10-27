/*
 * Prescribing screen behaviour.
 *
 * Every call goes through api.php, which enforces the ACL and CSRF token.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var cfg = window.erxConfig || {};
    var controlledRule = cfg.controlled || { allowed: false, mayDraft: false, message: '', idmeUrl: 'idme.php' };
    var $ = function (id) { return document.getElementById(id); };
    var chosenDrug = null;

    var api = window.erxApi;
    var esc = api.esc;
    var call = api.call;

    function alertBox(message, kind, link) {
        var el = $('erx-alert');
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = message;
        if (link) {
            el.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = link.href;
            a.textContent = link.text;
            el.appendChild(a);
        }
        el.hidden = false;
        if (kind === 'success') {
            setTimeout(function () { el.hidden = true; }, 4000);
        }
    }

    // The result of an action, where the prescriber is looking. The banner at
    // the top is out of sight when the button pressed is further down the
    // page, so results appear in the corner instead. Successes fade; anything
    // that needs reading stays until it is closed.
    function toast(message, kind, link) {
        var box = $('erx-toasts');
        var el = document.createElement('div');
        el.className = 'alert alert-' + (kind || 'info') + ' erx-toast';
        el.setAttribute('role', kind === 'danger' ? 'alert' : 'status');

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'close';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { el.remove(); });
        el.appendChild(close);

        el.appendChild(document.createTextNode(message));
        if (link) {
            el.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = link.href;
            a.textContent = link.text;
            el.appendChild(a);
        }

        box.appendChild(el);
        while (box.children.length > 3) { box.removeChild(box.firstChild); }
        if (kind === 'success' || kind === 'info') {
            setTimeout(function () { el.remove(); }, kind === 'success' ? 5000 : 10000);
        }
    }

    /* ── controlled substances ───────────────────────────── */

    // The warning badge and the red panel stay whatever the standing; what
    // changes is whether the draft can be saved and what the prescriber is
    // told. api.php makes the real decision when the draft is sent.
    function controlledNotice() {
        if (controlledRule.allowed) {
            return {
                kind: 'info',
                text: 'Controlled substance. ID.me two-factor authentication is active'
                    + (controlledRule.mfaExpiresAt ? ' until ' + localTime(controlledRule.mfaExpiresAt) : '') + '.'
            };
        }
        if (controlledRule.mayDraft) {
            // MFA is asked for when the draft is sent, not now.
            return {
                kind: 'info',
                text: 'Controlled substance. You will be asked for your ID.me code when you send it.'
            };
        }
        if (controlledRule.reason === 'not_epcs') {
            // ID.me cannot fix this one: the prescriber's eRx type is set on
            // their user record, and LogiCoy knows them as NON_EPCS.
            return {
                kind: 'danger',
                text: 'Controlled substance. ' + controlledRule.message
                    + ' An administrator sets the eRx prescribing type to EPCS under Administration, Users,'
                    + ' and the prescriber is then registered with LogiCoy as EPCS.'
            };
        }
        return {
            kind: 'danger',
            text: 'Controlled substance. ' + (controlledRule.message || 'It cannot be prescribed here.'),
            link: { href: controlledRule.idmeUrl, text: 'Open ID.me Verification' }
        };
    }

    function localTime(iso) {
        var d = new Date(iso);
        return isNaN(d.getTime()) ? iso : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Counts the MFA banner down so a prescriber mid-visit can see the
    // session is about to lapse, rather than learning it from a refused send.
    function startMfaCountdown() {
        var banner = $('erx-mfa-banner');
        if (!banner || !banner.dataset.expires) { return; }
        var expires = new Date(banner.dataset.expires).getTime();
        var out = banner.querySelector('.erx-mfa-left');
        var tick = function () {
            var left = Math.max(0, Math.round((expires - Date.now()) / 1000));
            var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60);
            out.textContent = left <= 0 ? '0 minutes' : (h ? h + ' h ' : '') + m + ' min';
            if (left <= 0) {
                controlledRule.allowed = false;
                controlledRule.reason = 'mfa_expired';
                controlledRule.message = 'Your ID.me two-factor session has expired. Complete it again before sending.';
                banner.className = 'alert alert-warning py-2';
                banner.textContent = 'Your ID.me two-factor session has expired. ';
                var a = document.createElement('a');
                a.href = controlledRule.idmeUrl;
                a.textContent = 'Complete it again';
                banner.appendChild(a);
                clearInterval(timer);
                updateSaveState();
            }
        };
        tick();
        var timer = setInterval(tick, 30000);
    }

    /* ── drug search ─────────────────────────────────────── */

    var searchTimer = null;

    function onDrugInput() {
        var term = $('drug-search').value.trim();
        clearTimeout(searchTimer);

        if (term.length < 3) {
            $('drug-results').hidden = true;
            return;
        }

        // Debounced: every keystroke would otherwise be a backend call.
        searchTimer = setTimeout(function () {
            var box = $('drug-results');
            box.hidden = false;
            box.innerHTML = '<div class="erx-spinner">Searching...</div>';

            call('drug-search', { term: term }).then(function (data) {
                renderDrugs(data.drugs || []);
            }).catch(function (e) {
                box.innerHTML = '<div class="erx-spinner">' + esc(e.message) + '</div>';
            });
        }, 350);
    }

    function renderDrugs(drugs) {
        var box = $('drug-results');
        if (!drugs.length) {
            box.innerHTML = '<div class="erx-spinner">No matches.</div>';
            return;
        }

        box.innerHTML = drugs.map(function (d, i) {
            var meta = [d.strength, d.form, d.manufacturer, 'NDC ' + d.ndcNumber]
                .filter(Boolean).join(' · ');
            return '<div class="erx-result" tabindex="0" data-i="' + i + '">'
                + '<div class="name">' + esc(d.displayName || d.drugName)
                + (d.isControlled ? ' <span class="badge badge-danger">C-' + esc(d.scheduleClass) + '</span>' : '')
                + '</div><div class="meta">' + esc(meta) + '</div></div>';
        }).join('');

        Array.prototype.forEach.call(box.querySelectorAll('.erx-result'), function (el) {
            var pick = function () { chooseDrug(drugs[parseInt(el.dataset.i, 10)]); };
            el.addEventListener('click', pick);
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
            });
        });
    }

    function chooseDrug(drug) {
        chosenDrug = drug;
        $('ndcNumber').value = drug.ndcNumber;
        $('drugName').value = drug.drugName;
        $('isControlled').value = drug.isControlled ? 'true' : 'false';

        $('drug-results').hidden = true;
        $('drug-search').value = '';

        var chosen = $('drug-chosen');
        chosen.hidden = false;
        chosen.className = 'erx-chosen' + (drug.isControlled ? ' erx-controlled' : '');
        chosen.innerHTML = '<button type="button" class="btn btn-sm btn-link clear">Change</button>'
            + '<div><strong>' + esc(drug.displayName || drug.drugName) + '</strong></div>'
            + '<div class="meta small">NDC ' + esc(drug.ndcNumber) + '</div>'
            + (drug.isControlled
                ? '<div class="small mt-1"><strong>Schedule ' + esc(drug.scheduleClass) + '.</strong> '
                  + esc(drug.controlledWarning || 'Controlled substance: EPCS, ID.me verification and two-factor authentication are required to send it.')
                  + '</div>'
                : '');

        chosen.querySelector('.clear').addEventListener('click', clearDrug);
        updateSaveState();
    }

    function clearDrug() {
        chosenDrug = null;
        $('ndcNumber').value = '';
        $('drugName').value = '';
        $('isControlled').value = 'false';
        $('drug-chosen').hidden = true;
        updateSaveState();
    }

    /* ── pharmacy search ─────────────────────────────────── */

    function searchPharmacies() {
        var box = $('ph-results');
        box.hidden = false;
        box.innerHTML = '<div class="erx-spinner">Searching...</div>';

        call('pharmacy-search', { name: $('ph-name').value.trim(), zip: $('ph-zip').value.trim() })
            .then(function (data) {
                var list = data.pharmacies || [];
                if (!list.length) {
                    box.innerHTML = '<div class="erx-spinner">No pharmacies found.</div>';
                    return;
                }
                box.innerHTML = list.map(function (p, i) {
                    var meta = [p.address, p.city, p.state, p.postalCode].filter(Boolean).join(', ');
                    return '<div class="erx-result" tabindex="0" data-i="' + i + '">'
                        + '<div class="name">' + esc(p.name)
                        + (p.isMailOrder ? ' <span class="badge badge-info">Mail order</span>' : '')
                        + '</div><div class="meta">' + esc(meta) + '</div></div>';
                }).join('');

                Array.prototype.forEach.call(box.querySelectorAll('.erx-result'), function (el) {
                    el.addEventListener('click', function () {
                        choosePharmacy(list[parseInt(el.dataset.i, 10)]);
                    });
                });
            })
            .catch(function (e) {
                box.innerHTML = '<div class="erx-spinner">' + esc(e.message) + '</div>';
            });
    }

    // The last pharmacy chosen, so it comes back for the next new
    // prescription after a draft has been edited.
    var lastPharmacy = null;

    function choosePharmacy(p) {
        if (!p.organizationId) {
            toast('This pharmacy has no directory ID and cannot receive prescriptions.', 'danger');
            return;
        }
        lastPharmacy = p;
        $('pharmacyOrgId').value = p.organizationId;
        $('pharmacyName').value = p.name || '';
        $('ph-results').hidden = true;
        $('ph-search').hidden = true;
        $('ph-keep').hidden = true;

        var chosen = $('ph-chosen');
        chosen.hidden = false;
        chosen.innerHTML = '<button type="button" class="btn btn-sm btn-link clear">Change</button>'
            + '<div><strong>' + esc(p.name) + '</strong>'
            + (p.isMailOrder ? ' <span class="badge badge-info">Mail order</span>' : '') + '</div>'
            + '<div class="meta small">'
            + esc([p.address, p.city, p.state, p.postalCode].filter(Boolean).join(', ')) + '</div>'
            + '<div class="meta small">Stays selected for the next prescription for this patient.</div>';
        chosen.querySelector('.clear').addEventListener('click', clearPharmacy);
        markInvalid('pharmacy', false);
        updateSaveState();
    }

    function clearPharmacy() {
        $('pharmacyOrgId').value = '';
        $('pharmacyName').value = '';
        $('ph-chosen').hidden = true;
        $('ph-search').hidden = false;
        // While editing, an empty pick means "keep the draft's own pharmacy".
        $('ph-keep').hidden = !isEditing();
        $('ph-name').focus();
        updateSaveState();
    }

    function onPharmacyKey(e) {
        // Enter searches rather than submitting the prescription form.
        if (e.key === 'Enter') {
            e.preventDefault();
            searchPharmacies();
        }
    }

    /* ── what is still missing ───────────────────────────── */

    function isEditing() {
        return $('medicationUuid').value !== '';
    }

    // The same rules DraftMapper applies on the server, checked here first
    // so the prescriber sees what is missing next to the field rather than in
    // a list after a round trip. The server still decides.
    function missingFields() {
        var missing = [];
        if (!$('ndcNumber').value || !$('drugName').value) { missing.push(['drug', 'drug', null]); }
        if (!$('sig').value.trim()) { missing.push(['sig', 'directions', $('sig')]); }
        if ($('quantity').value.trim() === '') { missing.push(['quantity', 'quantity', $('quantity')]); }
        if (!(parseInt($('daysSupply').value, 10) > 0)) { missing.push(['daysSupply', 'days supply', $('daysSupply')]); }
        if (parseInt($('refills').value || '0', 10) < 0) { missing.push(['refills', 'refills', $('refills')]); }
        if (!isEditing() && !$('pharmacyOrgId').value) { missing.push(['pharmacy', 'pharmacy', null]); }
        return missing;
    }

    function markInvalid(key, on) {
        var input = { sig: 'sig', quantity: 'quantity', daysSupply: 'daysSupply', refills: 'refills' }[key];
        if (input) {
            $(input).classList.toggle('is-invalid', on);
            return;
        }
        var feedback = document.querySelector('.invalid-feedback[data-for="' + key + '"]');
        if (feedback) { feedback.classList.toggle('d-block', on); }
        var section = key === 'drug' ? $('step-drug') : $('step-pharmacy');
        section.classList.toggle('erx-step-invalid', on);
    }

    function updateMissing() {
        var out = $('rx-missing');
        if (!cfg.canPrescribe) {
            out.textContent = out.dataset.blocked;
            return;
        }
        var missing = missingFields();
        // Clear marks as fields are filled in; new marks appear only on Save.
        ['drug', 'sig', 'quantity', 'daysSupply', 'refills', 'pharmacy'].forEach(function (key) {
            if (!missing.some(function (m) { return m[0] === key; })) { markInvalid(key, false); }
        });
        out.textContent = missing.length
            ? out.dataset.prefix + ': ' + missing.map(function (m) { return m[1]; }).join(', ')
            : out.dataset.ready;
        out.classList.toggle('text-success', missing.length === 0);
        out.classList.toggle('text-muted', missing.length > 0);
    }

    /* ── drafts ──────────────────────────────────────────── */

    function setStatus(ok) {
        var badge = $('erx-status');
        badge.textContent = ok ? badge.dataset.ok : badge.dataset.fail;
        badge.className = ok ? 'badge badge-success' : 'badge badge-light border text-muted';
    }

    function loadDrafts() {
        var box = $('drafts');
        if (!cfg.hasCredential) {
            setStatus(false);
            box.innerHTML = '<p class="text-muted mb-0">No credential loaded.</p>';
            return;
        }
        box.innerHTML = '<div class="erx-spinner">Loading...</div>';

        call('draft-list').then(function (data) {
            setStatus(true);
            renderDrafts(data.drafts || []);
        }).catch(function (e) {
            setStatus(false);
            box.innerHTML = '<p class="text-muted mb-0">' + esc(e.message) + '</p>';
        });
    }

    function renderDrafts(drafts) {
        var box = $('drafts');
        // The backend keeps listing a removed draft, as DELETED. It cannot be
        // sent or edited, so it does not belong in a list of drafts to send.
        drafts = drafts.filter(function (d) { return String(d.status).toUpperCase() !== 'DELETED'; });
        if (!drafts.length) {
            box.innerHTML = '<p class="text-muted mb-0">No drafts yet. Saved prescriptions wait here until you send them.</p>';
            currentDrafts = [];
            return;
        }

        var editingUuid = $('medicationUuid').value;
        var page = api.paged(drafts, draftsPage, PAGE_SIZE);
        box.innerHTML = page.items.map(function (d) {
            var meta = [
                'Qty ' + d.quantity, d.daysSupply + ' days', d.refills + ' refills',
                d.substitutionAllowed ? 'Substitution allowed' : 'Dispense as written'
            ].join(' · ');
            return '<div class="erx-draft' + (d.medicationUuid === editingUuid ? ' erx-draft-editing' : '')
                + '" data-uuid="' + esc(d.medicationUuid) + '" data-drug="' + esc(d.drugName)
                + '" data-pharmacy="' + esc(d.pharmacyName || '') + '">'
                + '<div class="d-flex justify-content-between"><div class="drug">' + esc(d.drugName) + '</div>'
                + '<span class="badge badge-light border">' + esc(d.status) + '</span></div>'
                + '<div>' + esc(d.sig) + '</div>'
                + '<div class="meta">' + esc(meta) + '</div>'
                + '<div class="actions">'
                + '<button class="btn btn-sm btn-primary" data-do="rx-send">Send</button>'
                + '<button class="btn btn-sm btn-outline-secondary" data-do="draft-edit">Edit</button>'
                + '<button class="btn btn-sm btn-outline-secondary" data-do="draft-remove">Remove</button>'
                + '</div></div>';
        }).join('') + page.controls;
        currentDrafts = drafts;
    }

    var currentDrafts = [];

    // Cards per page in the drafts and sent lists. They share the side
    // column with the form, so a long history must not push it off screen.
    var PAGE_SIZE = 5;
    var draftsPage = { page: 0 };
    var sentPage = { page: 0 };

    /* ── editing a draft ─────────────────────────────────── */

    // The draft comes back from the backend without its schedule, so the
    // controlled badge is not shown while editing; api.php and the backend
    // still decide on save and on send.
    function editDraft(d) {
        $('medicationUuid').value = d.medicationUuid;
        $('ndcNumber').value = d.ndcNumber || '';
        $('drugName').value = d.drugName || '';
        $('isControlled').value = 'false';
        $('sig').value = d.sig || '';
        $('quantity').value = d.quantity || '';
        $('daysSupply').value = d.daysSupply || '';
        $('refills').value = d.refills || '0';
        if (d.effectiveDate) { $('effectiveDate').value = String(d.effectiveDate).slice(0, 10); }
        $('notesToPharmacist').value = d.notes || '';
        $('substitutionAllowed').checked = d.substitutionAllowed === true;

        chosenDrug = { ndcNumber: d.ndcNumber, drugName: d.drugName, isControlled: false };
        var chosen = $('drug-chosen');
        chosen.hidden = false;
        chosen.className = 'erx-chosen';
        chosen.innerHTML = '<button type="button" class="btn btn-sm btn-link clear">Change</button>'
            + '<div><strong>' + esc(d.drugName) + '</strong></div>'
            + '<div class="meta small">NDC ' + esc(d.ndcNumber) + '</div>';
        chosen.querySelector('.clear').addEventListener('click', clearDrug);
        $('drug-results').hidden = true;

        // The draft's pharmacy is not in the listing, so it is not shown;
        // leaving the pick empty keeps it, and choosing one replaces it.
        $('pharmacyOrgId').value = '';
        $('pharmacyName').value = '';
        $('ph-chosen').hidden = true;
        $('ph-search').hidden = false;
        $('ph-keep').hidden = false;

        setEditMode(true);
        $('erx-alert').hidden = true;
        renderDrafts(currentDrafts);
        updateSaveState();
        $('rx-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function setEditMode(on) {
        var title = $('rx-form-title');
        title.textContent = on ? title.dataset.labelEdit : title.dataset.labelNew;
        $('btn-save').textContent = on
            ? ($('btn-save').dataset.labelEdit || 'Update draft')
            : ($('btn-save').dataset.labelNew || 'Save draft');
        $('btn-cancel-edit').hidden = !on;
        document.querySelector('.erx-compose').classList.toggle('erx-compose-editing', on);
    }

    function cancelEdit() {
        $('medicationUuid').value = '';
        $('rx-form').reset();
        clearDrug();
        $('ph-keep').hidden = true;
        if (!$('pharmacyOrgId').value && lastPharmacy) {
            choosePharmacy(lastPharmacy);
        } else if (!$('pharmacyOrgId').value) {
            $('ph-chosen').hidden = true;
            $('ph-search').hidden = false;
        }
        setEditMode(false);
        $('erx-alert').hidden = true;
        ['drug', 'sig', 'quantity', 'daysSupply', 'refills', 'pharmacy'].forEach(function (k) { markInvalid(k, false); });
        renderDrafts(currentDrafts);
        updateSaveState();
    }

    function onDraftAction(e) {
        if (api.pagerClick(e, draftsPage, function () { renderDrafts(currentDrafts); })) { return; }
        var btn = e.target.closest('button[data-do]');
        if (!btn) { return; }

        var card = btn.closest('.erx-draft');
        var uuid = card.dataset.uuid;
        var action = btn.dataset.do;

        if (action === 'draft-edit') {
            var draft = currentDrafts.filter(function (d) { return d.medicationUuid === uuid; })[0];
            if (draft) { editDraft(draft); }
            return;
        }

        var prompts = {
            'rx-send': {
                title: 'Send prescription',
                message: 'Send this prescription to ' + pharmacyLabel(card) + '?',
                confirmText: 'Send'
            },
            'draft-remove': {
                title: 'Remove draft',
                message: 'Remove this draft? It has not been transmitted.',
                confirmText: 'Remove draft',
                danger: true
            }
        };
        window.erxConfirm(prompts[action]).then(function (ok) {
            if (ok) { runDraftAction(card, action, uuid); }
        });
    }

    // The pharmacy a draft or sent card was saved with, or a plain fallback
    // for one saved before the module kept the name.
    function pharmacyLabel(card) {
        return card.dataset.pharmacy || 'the pharmacy';
    }

    function runDraftAction(card, action, uuid) {
        var buttons = function (disabled) {
            Array.prototype.forEach.call(card.querySelectorAll('button'), function (b) { b.disabled = disabled; });
        };
        buttons(true);

        call(action, { medicationUuid: uuid, drugName: card.dataset.drug || '' }).then(function (data) {
            // Sent, but not written to the chart: say so rather than
            // reporting plain success and leaving the Prescriptions card
            // to contradict it.
            if (data && data.warning) {
                toast(data.warning, 'warning');
            } else {
                toast(action === 'rx-send' ? 'Prescription sent.' : 'Done.', 'success');
            }
            loadDrafts();
            loadSent();
        }).catch(function (err) {
            buttons(false);
            // A controlled substance refused only for want of MFA: ask for
            // the code here and send again once ID.me accepts it.
            if (action === 'rx-send' && (err.reason === 'mfa_required' || err.reason === 'mfa_expired')) {
                askForMfa(function () { runDraftAction(card, action, uuid); });
                return;
            }
            toast(err.message, 'danger');
        });
    }

    /* ── Send-time MFA dialog ─────────────────────────────── */

    var mfaCard = null;

    function askForMfa(onPassed) {
        var modal = $('erx-mfa-modal');
        if (!modal || !window.ErxMfaCard) {
            toast('Complete ID.me two-factor authentication first.', 'danger',
                { href: controlledRule.idmeUrl, text: 'Open ID.me Verification' });
            return;
        }
        var root = modal.querySelector('.erx-mfa');
        root.dataset.facility = cfg.facilityId || root.dataset.facility;

        if (mfaCard) { mfaCard.stopTimers(); }
        mfaCard = new window.ErxMfaCard(root, {
            autoStart: true,
            onPassed: function (mfa) {
                controlledRule.allowed = true;
                controlledRule.reason = '';
                controlledRule.mfaExpiresAt = mfa.expires_at;
                closeMfa();
                toast('ID.me accepted. Sending the prescription...', 'info');
                onPassed();
            }
        });
        modal.hidden = false;
        mfaCard.load();
    }

    function closeMfa() {
        var modal = $('erx-mfa-modal');
        if (mfaCard) { mfaCard.stopTimers(); }
        if (modal) { modal.hidden = true; }
    }

    /* ── sent prescriptions ──────────────────────────────── */

    var SENT_STATUS = {
        sent: ['Sent', 'success'],
        cancel_queued: ['Cancel queued', 'info'],
        cancel_requested: ['Cancel sent — awaiting pharmacy', 'info'],
        cancelled: ['Cancelled', 'secondary'],
        cancel_denied: ['Cancel denied by pharmacy', 'warning'],
        cancel_failed: ['Cancel not delivered', 'danger']
    };

    function loadSent() {
        var box = $('sent');
        if (!cfg.hasCredential) {
            box.innerHTML = '<p class="text-muted mb-0">No credential loaded.</p>';
            return;
        }

        call('rx-sent-list').then(function (data) {
            renderSent(data.prescriptions || []);
        }).catch(function (e) {
            box.innerHTML = '<p class="text-muted mb-0">' + esc(e.message) + '</p>';
        });
    }

    function renderSent(list) {
        var box = $('sent');
        currentSent = list;
        if (!list.length) {
            box.innerHTML = '<p class="text-muted mb-0">Nothing sent from this screen yet.</p>';
            return;
        }

        var page = api.paged(list, sentPage, PAGE_SIZE);
        box.innerHTML = page.items.map(function (r) {
            var s = SENT_STATUS[r.status] || [r.status, 'secondary'];
            var facts = [
                r.sentAt ? 'Sent ' + String(r.sentAt).replace(/:\d\d$/, '') : '',
                r.transmissionStatus ? 'Network: ' + r.transmissionStatus : '',
                r.fillStatus ? 'Pharmacy: ' + r.fillStatus : '',
                fillIndicatorFact(r)
            ].filter(Boolean).join(' · ');

            return '<div class="erx-draft" data-uuid="' + esc(r.medicationUuid)
                + '" data-pharmacy="' + esc(r.pharmacyName || '') + '">'
                + '<div class="d-flex justify-content-between"><div class="drug">'
                + esc(r.drugName || 'Prescription') + '</div>'
                + '<div><span class="badge badge-' + s[1] + '">' + esc(s[0]) + '</span></div></div>'
                + '<div class="meta">' + esc(facts) + '</div>'
                + (r.detail ? '<div class="small text-muted">' + esc(r.detail) + '</div>' : '')
                + (r.fillIndicatorStatus === 'failed' && r.fillIndicatorDetail
                    ? '<div class="small text-muted">Fill reports refused: ' + esc(r.fillIndicatorDetail) + '</div>'
                    : '')
                + (checkedStatus[r.medicationUuid]
                    ? '<div class="erx-checked small" role="status">' + esc(checkedStatus[r.medicationUuid]) + '</div>'
                    : '')
                + '<div class="actions">'
                + '<button class="btn btn-sm btn-outline-secondary" data-do="rx-status">Check status</button>'
                + (r.cancellable
                    ? '<button class="btn btn-sm btn-outline-danger" data-do="rx-cancel">Cancel Rx</button>'
                    : '')
                + (r.fillIndicatorRequestable
                    ? '<button class="btn btn-sm btn-outline-secondary" data-do="rx-fill-indicator">'
                        + (r.fillIndicatorStatus === 'failed' ? 'Request fill reports again' : 'Request fill reports')
                        + '</button>'
                    : '')
                + '</div></div>';
        }).join('') + page.controls;
    }

    var currentSent = [];

    // What the pharmacy was asked to report back about dispensing, and
    // whether that request got through. Nothing until a pharmacy status
    // arrives is expected while it is still queued; a refusal is spelled
    // out on its own line under the card, with the button to ask again.
    function fillIndicatorFact(r) {
        if (!r.fillIndicatorStatus) { return r.fillIndicatorRequestable ? 'Fill reports: not requested' : ''; }
        var what = r.fillIndicatorLabel || ('indicator ' + r.fillIndicator);
        if (r.fillIndicatorStatus === 'set') { return 'Fill reports: ' + what; }
        if (r.fillIndicatorStatus === 'queued') { return 'Fill reports: ' + what + ' (requesting)'; }
        return 'Fill reports: refused';
    }

    function onSentAction(e) {
        if (api.pagerClick(e, sentPage, function () { renderSent(currentSent); })) { return; }
        var btn = e.target.closest('button[data-do]');
        if (!btn) { return; }

        var card = btn.closest('.erx-draft');
        var action = btn.dataset.do;

        if (action !== 'rx-cancel') {
            runSentAction(card, btn, action);
            return;
        }
        window.erxConfirm({
            title: 'Cancel prescription',
            message: 'Send a cancellation request to ' + pharmacyLabel(card) + ' for this prescription?',
            confirmText: 'Send cancellation request',
            cancelText: 'Keep prescription',
            danger: true
        }).then(function (ok) {
            if (ok) { runSentAction(card, btn, action); }
        });
    }

    function runSentAction(card, btn, action) {
        Array.prototype.forEach.call(card.querySelectorAll('button'), function (b) { b.disabled = true; });
        if (action === 'rx-status') { btn.textContent = 'Checking...'; }
        if (action === 'rx-fill-indicator') { btn.textContent = 'Requesting...'; }

        var uuid = card.dataset.uuid;
        call(action, { medicationUuid: uuid }).then(function (data) {
            if (action === 'rx-cancel') {
                toast(data.queued
                    ? 'Cancel request queued. It is sent to the pharmacy in the background.'
                    : 'A cancel request for this prescription is already on its way.', 'success');
            } else if (action === 'rx-fill-indicator') {
                toast(data.queued
                    ? 'Fill report request queued. LogiCoy is asked in the background; refresh in a few minutes.'
                    : 'A fill report request for this prescription is already on its way.', 'success');
            } else {
                // Kept on the card it belongs to, so the answer is next to
                // the button that asked for it and survives the list reload.
                var when = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                var text = 'Status: ' + ([data.status, data.message].filter(Boolean).join(' — ') || 'no change');
                checkedStatus[uuid] = text + ' (checked ' + when + ')';
                toast(text, 'info');
            }
            loadSent();
        }).catch(function (err) {
            toast(err.message, 'danger');
            if (action === 'rx-status') { btn.textContent = 'Check status'; }
            if (action === 'rx-fill-indicator') { btn.textContent = 'Request fill reports'; }
            Array.prototype.forEach.call(card.querySelectorAll('button'), function (b) { b.disabled = false; });
        });
    }

    // medicationUuid => the last "Check status" answer, shown on that card.
    var checkedStatus = {};

    /* ── pharmacy requests ───────────────────────────────── */

    function loadRequests() {
        if (!cfg.hasCredential) {
            return;
        }
        var requests = new window.ErxRequests($('requests'), {
            onLoad: function (list) { $('requests-card').hidden = list.length === 0; }
        });
        requests.load();
    }

    /* ── form ────────────────────────────────────────────── */

    // Saving is refused outright without a credential and a prescriber
    // registration, or for a controlled drug this prescriber may not draft.
    function saveBlocked() {
        return !cfg.canPrescribe || ($('isControlled').value === 'true' && !controlledRule.mayDraft);
    }

    function updateSaveState() {
        var controlled = $('isControlled').value === 'true';
        $('btn-save').disabled = saveBlocked();
        if (controlled) {
            var notice = controlledNotice();
            alertBox(notice.text, notice.kind, notice.link);
        }
        updateMissing();
    }

    function saveDraft(e) {
        e.preventDefault();
        $('erx-alert').hidden = true;

        var missing = missingFields();
        if (missing.length) {
            missing.forEach(function (m) { markInvalid(m[0], true); });
            var first = missing[0];
            var focusEl = first[2] || (first[0] === 'drug' ? $('drug-search') : $('ph-name'));
            if (focusEl && !focusEl.closest('[hidden]')) { focusEl.focus(); }
            (first[0] === 'drug' ? $('step-drug') : first[0] === 'pharmacy' ? $('step-pharmacy') : focusEl)
                .scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        var form = $('rx-form');
        var params = {};
        Array.prototype.forEach.call(form.querySelectorAll('input'), function (i) {
            if (!i.name) { return; }
            params[i.name] = i.type === 'checkbox' ? (i.checked ? 'true' : 'false') : i.value;
        });

        var editing = params.medicationUuid !== '';
        $('btn-save').disabled = true;
        call(editing ? 'draft-update' : 'draft-add', params).then(function (data) {
            // Saved with LogiCoy but not written to the chart: say so, rather
            // than reporting plain success and leaving the Prescriptions
            // card to contradict it.
            if (data && data.warning) {
                toast(data.warning, 'warning');
            } else {
                toast(editing ? 'Draft updated.' : 'Draft saved.', 'success');
            }
            cancelEdit();
            loadDrafts();
            updateSaveState();
        }).catch(function (err) {
            // The error stays on screen. updateSaveState() would redraw the
            // controlled-substance notice over it, which hid every failed
            // save of a scheduled drug behind "you will be asked for your
            // ID.me code".
            toast((err.errors || [err.message]).join(' · '), 'danger');
            $('btn-save').disabled = saveBlocked();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        $('drug-search').addEventListener('input', onDrugInput);
        $('drug-search').addEventListener('keydown', function (e) {
            // Enter would otherwise submit the half-filled form.
            if (e.key === 'Enter') { e.preventDefault(); }
        });
        $('btn-ph-search').addEventListener('click', function (e) { e.preventDefault(); searchPharmacies(); });
        $('ph-name').addEventListener('keydown', onPharmacyKey);
        $('ph-zip').addEventListener('keydown', onPharmacyKey);
        ['sig', 'quantity', 'daysSupply', 'refills'].forEach(function (id) {
            $(id).addEventListener('input', updateMissing);
        });
        $('btn-refresh').addEventListener('click', function (e) { e.preventDefault(); loadDrafts(); });
        $('rx-form').addEventListener('submit', saveDraft);
        $('btn-cancel-edit').addEventListener('click', cancelEdit);
        $('drafts').addEventListener('click', onDraftAction);
        if ($('erx-mfa-cancel')) {
            $('erx-mfa-cancel').addEventListener('click', closeMfa);
        }
        $('sent').addEventListener('click', onSentAction);
        $('btn-refresh-sent').addEventListener('click', function (e) { e.preventDefault(); loadSent(); });
        loadDrafts();
        loadSent();
        loadRequests();
        startMfaCountdown();
        updateMissing();
    });
})();
