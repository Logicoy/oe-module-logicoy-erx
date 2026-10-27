/*
 * Pharmacy renewal, change and new prescription requests: list, review,
 * respond. A request for a patient with no chart here is shown with the
 * pharmacy's description of them and the ways to give it one: add them to
 * OpenEMR, link an existing chart, or deny without one.
 *
 * Used on the prescribing screen (one patient) and the inbox (the facility).
 * A response is queued, not sent inline; the background worker transmits it
 * and the list shows it as on its way until then.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var api = window.erxApi;
    var esc = api.esc;

    var TYPE_LABEL = { renewal: 'Renewal request', change: 'Change request', 'new-rx': 'New prescription request' };
    var DECISION_LABEL = {
        APPROVED: 'Approve',
        APPROVED_WITH_CHANGES: 'Approve with changes',
        REPLACE: 'Replace',
        DENIED: 'Deny',
        VALIDATED: 'Validated — no changes needed'
    };
    var STATE_LABEL = {
        open: ['Awaiting response', 'warning'],
        response_queued: ['Response queued', 'info'],
        response_failed: ['Response not delivered', 'danger'],
        responded: ['Responded', 'success']
    };

    // Approve with changes and Replace are the same answer under different
    // codes: both carry the prescriber's version of the medication.
    function changesMedication(decision) {
        return decision === 'APPROVED_WITH_CHANGES' || decision === 'REPLACE';
    }

    function badge(state) {
        var s = STATE_LABEL[state] || [state, 'secondary'];
        return '<span class="badge badge-' + s[1] + '">' + esc(s[0]) + '</span>';
    }

    function when(value) {
        return value ? String(value).replace(/:\d\d$/, '') : '';
    }

    function medicationBlock(title, m) {
        if (!m) {
            return '';
        }
        var facts = [
            m.quantity ? 'Qty ' + m.quantity : '',
            m.daysSupply ? m.daysSupply + ' days' : '',
            m.refills !== '' && m.refills !== undefined ? m.refills + ' refills' : '',
            m.writtenDate ? 'written ' + m.writtenDate : '',
            m.lastFillDate ? 'last filled ' + m.lastFillDate : ''
        ].filter(Boolean).join(' · ');

        return '<div class="erx-med"><div class="small text-muted">' + esc(title) + '</div>'
            + '<div><strong>' + esc(m.description || 'Unnamed medication') + '</strong>'
            + (m.strength ? ' ' + esc(m.strength) : '') + '</div>'
            + (m.sig ? '<div>' + esc(m.sig) + '</div>' : '')
            + '<div class="meta small">' + esc(facts) + (m.ndc ? ' · NDC ' + esc(m.ndc) : '') + '</div>'
            // The refill count the pharmacy asks for is the decision being
            // made, so it stands out from the muted facts.
            + (m.pharmacyRequestedRefills
                ? '<div class="erx-requested-refills">Pharmacy requests '
                    + '<strong>' + esc(m.pharmacyRequestedRefills) + ' refills</strong></div>'
                : '')
            + (m.note ? '<div class="small">Note: ' + esc(m.note) + '</div>' : '')
            + '</div>';
    }

    // Requests per page. The inbox lists a whole facility's open requests;
    // the prescribing screen one patient's, which rarely needs a second page.
    var PAGE_SIZE = 10;

    function Requests(box, options) {
        this.box = box;
        this.options = options || {};
        this.requests = [];
        this.paging = { page: 0 };
        box.addEventListener('click', this.onClick.bind(this));
        box.addEventListener('change', this.onChange.bind(this));
        box.addEventListener('input', this.onInput.bind(this));
        box.addEventListener('submit', this.onSubmit.bind(this));
        // Drug results are focusable rows: Enter or space picks one.
        box.addEventListener('keydown', function (e) {
            var result = e.target.closest && e.target.closest('.erx-drug-results .erx-result');
            if (result && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                result.click();
            }
        });
    }

    Requests.prototype.load = function () {
        var self = this;
        var params = this.options.scope ? { scope: this.options.scope } : {};

        return api.call('pharmacy-requests', params).then(function (data) {
            self.requests = data.requests || [];
            self.render();
            if (self.options.onLoad) {
                self.options.onLoad(self.requests);
            }
        }).catch(function (e) {
            self.box.innerHTML = '<p class="text-muted mb-0">' + esc(e.message) + '</p>';
        });
    };

    Requests.prototype.render = function () {
        var self = this;
        if (!this.requests.length) {
            this.box.innerHTML = '<p class="text-muted mb-0">No pharmacy requests awaiting a response.</p>';
            return;
        }

        var page = api.paged(this.requests, this.paging, PAGE_SIZE);
        this.box.innerHTML = page.items.map(function (r) {
            var patient = esc(r.patientName || 'Patient not named');
            if (self.options.patientUrl && r.pid) {
                patient = '<a href="' + esc(self.options.patientUrl(r.pid)) + '">' + patient + '</a>';
            }
            if (!r.pid) {
                // No chart here yet: Review offers to add or link one.
                patient += ' <span class="badge badge-warning">Not in OpenEMR</span>';
            }
            var canRespond = r.state === 'open' || r.state === 'response_failed';

            return '<div class="erx-request" data-uuid="' + esc(r.notificationUuid) + '">'
                + '<div class="d-flex justify-content-between">'
                + '<div><strong>' + esc(TYPE_LABEL[r.type] || 'Pharmacy request') + '</strong>'
                + (r.priority === 'HIGH' ? ' <span class="badge badge-danger">High priority</span>' : '')
                + (self.options.showPatient ? ' · ' + patient : '')
                + '</div><div>' + badge(r.state) + '</div></div>'
                + '<div>' + esc(r.message) + '</div>'
                + '<div class="meta small">'
                + esc(['Received ' + when(r.requestedAt), r.dueAt ? 'due ' + when(r.dueAt) : '',
                    r.clinicianName ? 'for ' + r.clinicianName : ''].filter(Boolean).join(' · '))
                + '</div>'
                + (r.lastError ? '<div class="small text-danger">' + esc(r.lastError) + '</div>' : '')
                + (canRespond
                    ? '<div class="actions"><button class="btn btn-sm btn-primary" data-do="review">Review</button></div>'
                    : (r.responseCode ? '<div class="small">Response: ' + esc(r.responseCode) + '</div>' : ''))
                + '<div class="erx-review" hidden></div>'
                + '</div>';
        }).join('') + page.controls;
    };

    Requests.prototype.onClick = function (e) {
        if (api.pagerClick(e, this.paging, this.render.bind(this))) {
            return;
        }
        var result = e.target.closest('.erx-drug-results .erx-result');
        if (result) {
            var results = result.closest('.erx-drug-results');
            pickDrug(result.closest('form'), (results.erxDrugs || [])[parseInt(result.dataset.i, 10)]);
            return;
        }

        var btn = e.target.closest('button[data-do]');
        if (!btn) {
            return;
        }
        e.preventDefault();
        var card = btn.closest('.erx-request');
        var form = btn.closest('form');

        if (btn.dataset.do === 'review') {
            this.review(card);
        } else if (btn.dataset.do === 'add-patient') {
            this.addPatient(card, btn);
        } else if (btn.dataset.do === 'link-patient') {
            this.linkPatient(card, btn);
        } else if (btn.dataset.do === 'find-patient') {
            var finder = card.querySelector('.erx-patient-picker');
            finder.hidden = false;
            finder.querySelector('.erx-patient-search').focus();
        } else if (btn.dataset.do === 'close') {
            card.querySelector('.erx-review').hidden = true;
        } else if (btn.dataset.do === 'change-drug') {
            var picker = form.querySelector('.erx-drug-picker');
            picker.hidden = false;
            picker.querySelector('.erx-drug-search').focus();
        } else if (btn.dataset.do === 'revert-drug') {
            renderChosenDrug(form, null);
            form.querySelector('.erx-drug-picker').hidden = true;
        }
    };

    // Debounced, like the prescribe screen: every keystroke would otherwise
    // be a backend call.
    Requests.prototype.onInput = function (e) {
        if (e.target.classList.contains('erx-patient-search')) {
            this.searchPatients(e.target);
            return;
        }
        if (!e.target.classList.contains('erx-drug-search')) {
            return;
        }
        var input = e.target;
        var results = input.closest('.erx-drug-picker').querySelector('.erx-drug-results');
        var term = input.value.trim();
        clearTimeout(input.erxTimer);

        if (term.length < 3) {
            results.hidden = true;
            return;
        }
        input.erxTimer = setTimeout(function () {
            results.hidden = false;
            results.innerHTML = '<div class="erx-spinner">Searching...</div>';
            api.call('drug-search', { term: term }).then(function (data) {
                renderDrugResults(results, data.drugs || []);
            }).catch(function (err) {
                results.innerHTML = '<div class="erx-spinner">' + esc(err.message) + '</div>';
            });
        }, 350);
    };

    Requests.prototype.review = function (card) {
        var panel = card.querySelector('.erx-review');
        panel.hidden = false;
        panel.innerHTML = '<div class="erx-spinner">Loading request from the pharmacy...</div>';

        api.call('pharmacy-request-details', { notificationUuid: card.dataset.uuid }).then(function (data) {
            var details = data.details || {};
            panel.erxDetails = details;
            panel.innerHTML = renderReview(details);
            var form = panel.querySelector('form.erx-respond');
            if (form) {
                form.erxRequested = details.requested || [];
                form.erxStart = details.dispensed || details.prescribed || {};
                fillChanges(form);
            }
        }).catch(function (err) {
            panel.innerHTML = '<div class="text-danger small">' + esc(err.message) + '</div>';
        });
    };

    function renderReview(d) {
        var html = '';
        var p = d.pharmacy || {};
        var pharmacyFacts = [p.address, p.phone, p.ncpdpId ? 'NCPDP ' + p.ncpdpId : ''].filter(Boolean).join(' · ');
        html += '<div class="small mb-2"><strong>' + esc(p.name || 'Pharmacy') + '</strong>'
            + (pharmacyFacts ? ' — ' + esc(pharmacyFacts) : '')
            + '</div>';
        if (d.patient && d.patient.name) {
            html += '<div class="small mb-2">Patient on request: <strong>' + esc(d.patient.name) + '</strong>'
                + esc([d.patient.dob ? ', DOB ' + d.patient.dob : '', d.patient.gender ? ', ' + d.patient.gender : '',
                    d.patient.phone ? ', ' + d.patient.phone : '', d.patient.address ? ', ' + d.patient.address : '']
                    .join(''))
                + '</div>';
        }
        if (d.patientLink) {
            html += renderPatientLink(d);
        }

        if (d.type === 'change') {
            html += '<div class="mb-2"><strong>Pharmacy asks:</strong> '
                + esc(d.requestDescription || d.requestCode || 'a change')
                + (d.subCodes || []).map(function (s) {
                    return '<div class="small">· ' + esc(s.description || s.code) + '</div>';
                }).join('')
                + '</div>';
        }

        if (d.type === 'new-rx') {
            html += medicationBlock('Pharmacy asks for', d.requested);
            if (d.message) {
                html += '<div class="small">' + esc(d.message) + '</div>';
            }
        }
        html += medicationBlock('Originally prescribed', d.prescribed);
        if (d.type === 'renewal') {
            html += medicationBlock('Pharmacy dispensed', d.dispensed);
        }
        if (d.followUp) {
            html += '<div class="small">Follow-up: ' + esc(d.followUp) + '</div>';
        }

        html += '<form class="erx-respond mt-2" data-type="' + esc(d.type) + '">';

        if (d.type === 'change') {
            var requested = d.requested || [];
            html += '<div class="small text-muted">'
                + 'Requested by the pharmacy — pick the one your response is about</div>';
            if (!requested.length) {
                html += '<div class="text-danger small">The pharmacy sent no requested medication.</div>';
            }
            requested.forEach(function (m, i) {
                html += '<label class="erx-choice"><input type="radio" name="requestedMedicationId" value="'
                    + esc(m.requestedMedicationId) + '"' + (i === 0 ? ' checked' : '') + '> '
                    + medicationBlock('Option ' + (i + 1), m) + '</label>';
            });
        }

        var decisions = d.decisions || [];
        // The pharmacy says which answers it takes.
        if (!decisions.length) {
            html += '<div class="alert alert-warning small">This request accepts no response this screen can '
                + 'send' + ((d.acceptedCodes || []).length ? ' (' + esc(d.acceptedCodes.join(', ')) + ')' : '')
                + ' — contact the pharmacy directly.</div>';
        }
        // An approved new prescription is written to a chart, so without one
        // only a denial can go.
        var unlinked = d.type === 'new-rx' && d.patientLink && !d.patientLink.pid;
        html += '<div class="small text-muted">Your response</div>'
            + '<div class="form-group mb-2">' + decisions.map(function (code) {
            var blocked = unlinked && code !== 'DENIED';
            // A lone choice is picked for the prescriber; sending still asks
            // them to confirm.
            return '<label class="mr-3"' + (blocked ? ' title="Add or link the patient in OpenEMR first"' : '')
                + '><input type="radio" name="decision" value="' + esc(code) + '"'
                + (decisions.length === 1 ? ' checked' : '') + (blocked ? ' disabled' : '') + '> '
                + esc(DECISION_LABEL[code] || code)
                + (blocked ? ' <span class="text-muted">(needs a chart)</span>' : '') + '</label>';
        }).join('') + '</div>';

        if (decisions.some(changesMedication)) {
            html += renderChanges(d.type);
        }

        if (d.type === 'renewal' || d.type === 'new-rx') {
            var onlyDeny = decisions.length === 1 && decisions[0] === 'DENIED';
            html += '<div class="form-group mb-2 erx-deny-reason"' + (onlyDeny ? '' : ' hidden') + '>'
                + '<select class="form-control form-control-sm" name="deniedReason">'
                + '<option value="">Reason for denial...</option>'
                + Object.keys(d.denialReasons || {}).map(function (code) {
                    return '<option value="' + esc(code) + '">' + esc(d.denialReasons[code]) + '</option>';
                }).join('')
                + '</select></div>';
        }

        html += '<div class="form-group mb-2"><textarea class="form-control form-control-sm" name="notes" rows="2" '
            + (d.type === 'new-rx' ? 'maxlength="210" ' : '')
            + 'placeholder="Notes to the pharmacy' + (d.type === 'change' ? ' (required to deny)' : '')
            + '"></textarea></div>'
            + '<button type="submit" class="btn btn-sm btn-primary"' + (decisions.length ? '' : ' disabled')
            + '>Send response</button> '
            + '<button type="button" class="btn btn-sm btn-link" data-do="close">Close</button>'
            + '<div class="erx-respond-error small text-danger mt-1"></div>'
            + '</form>';

        return html;
    }

    function patientRow(p) {
        return esc([p.name, p.dob ? 'DOB ' + p.dob : '', p.sex, p.pubpid ? 'chart ' + p.pubpid : '']
            .filter(Boolean).join(' · '));
    }

    // The chart a request is linked to, or the ways to give it one. The
    // candidates are charts with the same last name and birth date or first
    // name; a person confirms, nothing is linked on a name alone.
    function renderPatientLink(d) {
        var link = d.patientLink;
        if (link.pid) {
            return '<div class="erx-patient-link small mb-2">OpenEMR chart: <strong>' + esc(link.name || 'linked')
                + '</strong>' + (link.pubpid ? ' (' + esc(link.pubpid) + ')' : '') + '</div>';
        }
        var html = '<div class="erx-patient-link alert alert-warning py-2 px-3 mb-2">'
            + '<div><strong>This patient has no chart in OpenEMR.</strong> '
            + 'Add them from the pharmacy\'s details, link an existing chart, or deny the request.</div>';
        if ((link.candidates || []).length) {
            html += '<div class="small mt-1">Possibly the same person:</div>'
                + link.candidates.map(function (p) {
                    return '<div class="d-flex align-items-center small"><span class="mr-2">' + patientRow(p)
                        + '</span><button type="button" class="btn btn-sm btn-outline-primary py-0" '
                        + 'data-do="link-patient" data-pid="' + esc(p.pid) + '" data-name="' + esc(p.name)
                        + '" data-dob="' + esc(p.dob) + '">Link</button></div>';
                }).join('');
        }
        html += '<div class="actions">'
            + '<button type="button" class="btn btn-sm btn-primary" data-do="add-patient">Add to OpenEMR</button> '
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-do="find-patient">'
            + 'Link an existing chart</button></div>'
            + '<div class="erx-patient-picker mt-2" hidden>'
            + '<input class="form-control form-control-sm erx-patient-search" '
            + 'placeholder="Search charts: last, first — or a date of birth (YYYY-MM-DD)" aria-label="Search charts">'
            + '<div class="erx-results erx-patient-results mt-1" hidden></div>'
            + '</div>'
            + '<div class="erx-patient-error small text-danger mt-1"></div>'
            + '</div>';
        return html;
    }

    function renderPatientResults(box, patients) {
        box.hidden = false;
        if (!patients.length) {
            box.innerHTML = '<div class="erx-spinner">No charts match.</div>';
            return;
        }
        box.innerHTML = patients.map(function (p) {
            return '<div class="erx-result d-flex align-items-center"><span class="mr-2">' + patientRow(p)
                + '</span><button type="button" class="btn btn-sm btn-outline-primary py-0 ml-auto" '
                + 'data-do="link-patient" data-pid="' + esc(p.pid) + '" data-name="' + esc(p.name)
                + '" data-dob="' + esc(p.dob) + '">Link</button></div>';
        }).join('');
    }

    Requests.prototype.searchPatients = function (input) {
        var results = input.closest('.erx-patient-picker').querySelector('.erx-patient-results');
        var term = input.value.trim();
        clearTimeout(input.erxTimer);
        if (term.length < 2) {
            results.hidden = true;
            return;
        }
        input.erxTimer = setTimeout(function () {
            results.hidden = false;
            results.innerHTML = '<div class="erx-spinner">Searching...</div>';
            api.call('pharmacy-request-patient-search', { term: term }).then(function (data) {
                renderPatientResults(results, data.patients || []);
            }).catch(function (err) {
                results.innerHTML = '<div class="erx-spinner">' + esc(err.message) + '</div>';
            });
        }, 350);
    };

    // Reload the list and reopen this request, so the review shows the
    // chart it now has and offers the approval it could not before.
    Requests.prototype.reopen = function (uuid) {
        var self = this;
        return this.load().then(function () {
            var card = self.box.querySelector('.erx-request[data-uuid="' + uuid + '"]');
            if (card) {
                self.review(card);
            }
        });
    };

    Requests.prototype.addPatient = function (card, btn) {
        var self = this;
        var details = card.querySelector('.erx-review').erxDetails || {};
        var p = details.patient || {};
        var errorBox = card.querySelector('.erx-patient-error');
        window.erxConfirm({
            title: 'Add patient to OpenEMR',
            message: 'Create a chart for ' + (p.name || 'this patient') + (p.dob ? ', DOB ' + p.dob : '')
                + ', from the details the pharmacy sent, and link this request to it?',
            confirmText: 'Add patient'
        }).then(function (ok) {
            if (!ok) { return; }
            btn.disabled = true;
            errorBox.textContent = '';
            api.call('pharmacy-request-create-patient', { notificationUuid: card.dataset.uuid }).then(function () {
                return self.reopen(card.dataset.uuid);
            }).catch(function (err) {
                errorBox.textContent = err.message;
                btn.disabled = false;
            });
        });
    };

    Requests.prototype.linkPatient = function (card, btn) {
        var self = this;
        var errorBox = card.querySelector('.erx-patient-error');
        window.erxConfirm({
            title: 'Link to an existing chart',
            message: 'Link this request to ' + (btn.dataset.name || 'this chart')
                + (btn.dataset.dob ? ' (DOB ' + btn.dataset.dob + ')' : '')
                + '? Later requests for this patient from LogiCoy will go to the same chart.',
            confirmText: 'Link chart'
        }).then(function (ok) {
            if (!ok) { return; }
            btn.disabled = true;
            errorBox.textContent = '';
            api.call('pharmacy-request-link-patient', {
                notificationUuid: card.dataset.uuid,
                linkPid: btn.dataset.pid
            }).then(function () {
                return self.reopen(card.dataset.uuid);
            }).catch(function (err) {
                errorBox.textContent = err.message;
                btn.disabled = false;
            });
        });
    };

    // The prescriber's version of the requested medication. Each field is
    // labelled: once filled in, a placeholder alone no longer says which is
    // which. The drug is changed through the backend's own drug search, so
    // the NDC sent is one it returned.
    function renderChanges(type) {
        function field(name, label, type, cols, extra) {
            return '<div class="' + cols + '"><label class="erx-field">' + esc(label)
                + '<input class="form-control form-control-sm" name="' + name + '" type="' + type + '"'
                + (extra || '') + '></label></div>';
        }

        return '<div class="erx-changes" hidden>'
            + '<div class="erx-changes-title">Your changes</div>'
            + '<div class="small text-muted mb-2">Starts from '
            + (type === 'renewal' ? 'the medication the pharmacy dispensed' : 'the requested medication selected above')
            + '. Change only what you mean to; LogiCoy codes the script.</div>'
            + '<input type="hidden" name="ndcNumber"><input type="hidden" name="drugName">'
            + '<div class="form-group mb-2"><span class="erx-field">Drug</span>'
            + '<div class="erx-chosen erx-drug-chosen"></div>'
            + '<div class="erx-drug-picker mt-1" hidden>'
            + '<input class="form-control form-control-sm erx-drug-search" '
            + 'placeholder="Search by drug name or NDC (3+ characters)" aria-label="Search drugs">'
            + '<div class="erx-results erx-drug-results mt-1" hidden></div>'
            + '</div></div>'
            + '<div class="form-row">'
            + field('quantity', 'Quantity', 'text', 'col-4 col-md-2')
            + field('daysSupply', 'Days supply', 'number', 'col-4 col-md-2', ' min="1"')
            + field('refills', 'Refills', 'number', 'col-4 col-md-2', ' min="0" max="99"')
            + '</div>'
            + '<div class="form-group mb-0"><label class="erx-field">Directions (sig)'
            + '<textarea class="form-control form-control-sm" name="sig" rows="2"></textarea></label></div>'
            + '</div>';
    }

    // The drug box: the pharmacy's drug, or the one picked in its place.
    function renderChosenDrug(form, drug) {
        var box = form.querySelector('.erx-drug-chosen');
        var base = form.erxBaseDrug || {};
        form.ndcNumber.value = drug ? drug.ndcNumber : '';
        form.drugName.value = drug ? drug.drugName : '';
        box.className = 'erx-chosen erx-drug-chosen' + (drug ? ' erx-changed' : '');

        if (!drug) {
            box.innerHTML = '<button type="button" class="btn btn-sm btn-link clear" data-do="change-drug">'
                + 'Change drug</button>'
                + '<div><strong>' + esc(base.description || 'Unnamed medication') + '</strong>'
                + (base.strength ? ' ' + esc(base.strength) : '') + '</div>'
                + '<div class="meta small">' + esc([base.ndc ? 'NDC ' + base.ndc : '',
                    form.dataset.type === 'renewal' ? 'as the pharmacy dispensed' : 'as the pharmacy requested']
                    .filter(Boolean).join(' · ')) + '</div>';
            return;
        }

        var meta = [drug.strength, drug.form, drug.manufacturer, 'NDC ' + drug.ndcNumber].filter(Boolean).join(' · ');
        box.innerHTML = '<button type="button" class="btn btn-sm btn-link clear" data-do="revert-drug">'
            + 'Use the pharmacy\'s drug</button>'
            + '<div><strong>' + esc(drug.displayName || drug.drugName) + '</strong>'
            + ' <span class="badge badge-success">Changed</span></div>'
            + '<div class="meta small">' + esc(meta) + '</div>'
            + '<div class="meta small">Replaces ' + esc(base.description || 'the pharmacy\'s drug') + '</div>';
    }

    function renderDrugResults(box, drugs) {
        box.hidden = false;
        box.erxDrugs = drugs;
        if (!drugs.length) {
            box.innerHTML = '<div class="erx-spinner">No matches.</div>';
            return;
        }
        box.innerHTML = drugs.map(function (d, i) {
            var meta = [d.strength, d.form, d.manufacturer, 'NDC ' + d.ndcNumber].filter(Boolean).join(' · ');
            return '<div class="erx-result" tabindex="0" data-i="' + i + '">'
                + '<div class="name">' + esc(d.displayName || d.drugName)
                + (d.isControlled ? ' <span class="badge badge-danger">C-' + esc(d.scheduleClass) + '</span>' : '')
                + '</div><div class="meta">' + esc(meta) + '</div></div>';
        }).join('');
    }

    // Start the editable fields from the pharmacy's medication — on a change
    // the requested one that is selected, on a renewal the dispensed one —
    // so the prescriber changes only what they mean to.
    function fillChanges(form) {
        var box = form.querySelector('.erx-changes');
        if (!box) {
            return;
        }
        var m;
        if (form.dataset.type === 'renewal') {
            m = form.erxStart || {};
        } else {
            var picked = form.querySelector('input[name=requestedMedicationId]:checked');
            m = (form.erxRequested || []).filter(function (r) {
                return picked && String(r.requestedMedicationId) === picked.value;
            })[0] || {};
        }
        form.quantity.value = m.quantity || '';
        form.daysSupply.value = m.daysSupply || '';
        // A renewal starts from the refills the pharmacy asked for.
        var refills = m.pharmacyRequestedRefills || m.refills;
        form.refills.value = refills !== undefined && refills !== '' ? refills : '0';
        form.sig.value = m.sig || '';

        form.erxBaseDrug = { description: m.description || '', ndc: m.ndc || '', strength: m.strength || '' };
        renderChosenDrug(form, null);
        form.querySelector('.erx-drug-picker').hidden = true;
    }

    function pickDrug(form, drug) {
        var picker = form.querySelector('.erx-drug-picker');
        var results = form.querySelector('.erx-drug-results');
        if (drug.isControlled) {
            // A pharmacy response is not EPCS-signed here; the prescribe
            // screen is where a controlled substance goes through ID.me.
            results.innerHTML = '<div class="erx-spinner text-danger">Schedule ' + esc(drug.scheduleClass)
                + ': a controlled substance cannot be sent in a response from this screen. '
                + 'Deny and write a new prescription instead.</div>';
            return;
        }
        renderChosenDrug(form, drug);
        results.hidden = true;
        results.innerHTML = '';
        form.querySelector('.erx-drug-search').value = '';
        picker.hidden = true;
    }

    Requests.prototype.onChange = function (e) {
        var form = e.target.closest('form');
        if (!form) {
            return;
        }
        if (e.target.name === 'requestedMedicationId') {
            fillChanges(form);
            return;
        }
        if (e.target.name !== 'decision') {
            return;
        }
        var reason = form.querySelector('.erx-deny-reason');
        if (reason) {
            reason.hidden = e.target.value !== 'DENIED';
        }
        var changes = form.querySelector('.erx-changes');
        if (changes) {
            changes.hidden = !changesMedication(e.target.value);
        }
    };

    Requests.prototype.onSubmit = function (e) {
        var form = e.target.closest('form.erx-respond');
        if (!form) {
            return;
        }
        e.preventDefault();

        var self = this;
        var card = form.closest('.erx-request');
        var errorBox = form.querySelector('.erx-respond-error');
        var data = new FormData(form);
        var params = { notificationUuid: card.dataset.uuid };
        data.forEach(function (value, key) { params[key] = value; });

        if (!params.decision) {
            errorBox.textContent = 'Choose a response under "Your response".';
            return;
        }
        var label = DECISION_LABEL[params.decision] || params.decision;
        var summary = '';
        if (changesMedication(params.decision)) {
            summary = ' Sending ' + (params.ndcNumber ? params.drugName + ' (NDC ' + params.ndcNumber + '), ' : '')
                + 'quantity ' + params.quantity
                + (params.daysSupply ? ', ' + params.daysSupply + ' days' : '')
                + ', ' + params.refills + ' refills, "' + params.sig + '".';
        }
        window.erxConfirm({
            title: 'Respond to pharmacy',
            message: label + ' this request?' + summary
                + ' The response is sent to the pharmacy and cannot be taken back.',
            confirmText: 'Send response',
            danger: params.decision === 'DENIED'
        }).then(function (ok) {
            if (ok) { self.send(form, params); }
        });
    };

    Requests.prototype.send = function (form, params) {
        var self = this;
        var card = form.closest('.erx-request');
        var errorBox = form.querySelector('.erx-respond-error');
        var submit = form.querySelector('button[type=submit]');
        submit.disabled = true;
        errorBox.textContent = '';

        api.call('pharmacy-request-respond', params).then(function () {
            if (self.options.onResponded) {
                self.options.onResponded();
            }
            return self.load();
        }).catch(function (err) {
            errorBox.textContent = (err.errors || [err.message]).join(' · ');
            submit.disabled = false;
        });
    };

    window.ErxRequests = Requests;
})();
