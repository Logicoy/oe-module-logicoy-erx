/*
 * Recent pharmacy updates, shown read-only under the inbox: the pharmacy's
 * answers to cancel requests, and renewal and change requests already
 * answered here, so a request does not just vanish once it is answered.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var api = window.erxApi;
    var esc = api.esc;

    var TITLE = {
        'cancel-response': 'Cancel response',
        renewal: 'Renewal request',
        change: 'Change request',
        'new-rx': 'New prescription request'
    };
    var CANCEL_LABEL = {
        approved: ['Cancel approved', 'success'],
        denied: ['Cancel denied', 'warning'],
        pending: ['Outcome not yet retrieved', 'secondary'],
        unknown: ['Rx not sent from this install', 'secondary']
    };
    var RESPONSE_LABEL = {
        approved: ['Approved', 'success'],
        approved_with_changes: ['Approved with changes', 'success'],
        denied: ['Denied', 'warning'],
        validated: ['Validated', 'success']
    };

    function badge(u) {
        var labels = u.type === 'cancel-response' ? CANCEL_LABEL : RESPONSE_LABEL;
        var s = labels[u.outcome] || [u.outcome || 'Answered', 'secondary'];
        return '<span class="badge badge-' + s[1] + '">' + esc(s[0]) + '</span>';
    }

    function when(value) {
        return value ? String(value).replace(/:\d\d$/, '') : '';
    }

    var PAGE_SIZE = 10;

    function Updates(box, options) {
        this.box = box;
        this.options = options || {};
        this.updates = [];
        this.paging = { page: 0 };
        box.addEventListener('click', function (e) {
            api.pagerClick(e, this.paging, this.render.bind(this));
        }.bind(this));
    }

    Updates.prototype.load = function () {
        var self = this;

        return api.call('pharmacy-updates', {}).then(function (data) {
            self.updates = data.updates || [];
            self.render();
        }).catch(function (e) {
            self.box.innerHTML = '<p class="text-muted mb-0">' + esc(e.message) + '</p>';
        });
    };

    Updates.prototype.render = function () {
        var self = this;
        if (!this.updates.length) {
            this.box.innerHTML = '<p class="text-muted mb-0">No pharmacy updates in the last 30 days.</p>';
            return;
        }

        var page = api.paged(this.updates, this.paging, PAGE_SIZE);
        this.box.innerHTML = page.items.map(function (u) {
            var patient = esc(u.patientName || 'Unlinked patient');
            if (self.options.patientUrl && u.pid) {
                patient = '<a href="' + esc(self.options.patientUrl(u.pid)) + '">' + patient + '</a>';
            }

            var meta = u.type === 'cancel-response'
                ? ['Received ' + when(u.receivedAt)]
                : ['Responded ' + when(u.respondedAt) + (u.respondedBy ? ' by ' + u.respondedBy : '')];
            if (u.clinicianName) {
                meta.push('for ' + u.clinicianName);
            }

            return '<div class="erx-request">'
                + '<div class="d-flex justify-content-between">'
                + '<div><strong>' + esc(TITLE[u.type] || 'Pharmacy update') + '</strong> · ' + patient + '</div>'
                + '<div>' + badge(u) + '</div></div>'
                + (u.drugName ? '<div>' + esc(u.drugName) + '</div>' : '')
                + (u.detail ? '<div class="small">' + esc(u.detail) + '</div>' : '')
                + '<div class="meta small">' + esc(meta.join(' · ')) + '</div>'
                + (u.lastError ? '<div class="small text-danger">' + esc(u.lastError) + '</div>' : '')
                + '</div>';
        }).join('') + page.controls;
    };

    window.ErxPharmacyUpdates = Updates;
})();
