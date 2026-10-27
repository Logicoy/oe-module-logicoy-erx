/*
 * ID.me two-factor authentication card on the ID.me screen.
 *
 * One card per facility the prescriber is registered at. Every call goes
 * through api.php (ACL, CSRF, the prescriber's own credential); this file
 * only drives the choice of method, the code box or the push wait, and the
 * clock. The code is sent once and never kept.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var esc = window.erxApi.esc;
    var call = window.erxApi.call;

    var POLL_MS = 4000;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function localTime(iso) {
        var d = new Date(iso);
        return isNaN(d.getTime()) ? iso : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function remaining(iso) {
        var left = Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 1000));
        var h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
        if (h) { return h + ' h ' + m + ' min'; }
        if (m) { return m + ' min ' + (s < 10 ? '0' : '') + s + ' s'; }
        return s + ' s';
    }

    /*
     * options.onPassed(mfa) fires once, the moment a session becomes active
     * in this card — the prescribing screen uses it to carry on with the
     * send that asked for the code. options.autoStart skips the "Start"
     * button and goes straight to the method list when nothing is active.
     */
    function Card(root, options) {
        options = options || {};
        this.root = root;
        this.facilityId = root.dataset.facility;
        this.status = root.querySelector('.erx-mfa-status');
        this.body = root.querySelector('.erx-mfa-body');
        this.badge = root.parentNode.querySelector('.erx-mfa-badge');
        this.onPassed = options.onPassed || null;
        this.autoStart = !!options.autoStart;
        this.timer = null;
        this.poll = null;
        this.standing = null;
        this.wasActive = false;
    }

    Card.prototype.api = function (action, params) {
        params = params || {};
        params.facility_id = this.facilityId;
        return call(action, params);
    };

    Card.prototype.stopTimers = function () {
        clearInterval(this.timer);
        clearTimeout(this.poll);
        this.timer = null;
        this.poll = null;
    };

    Card.prototype.setBadge = function (kind, text) {
        if (!this.badge) { return; }
        this.badge.className = 'badge badge-' + kind + ' erx-mfa-badge';
        this.badge.textContent = text;
    };

    Card.prototype.say = function (text, kind) {
        this.status.className = 'erx-mfa-status small mb-2 text-' + (kind || 'muted');
        this.status.textContent = text;
    };

    Card.prototype.load = function () {
        var self = this;
        this.say('Checking...');
        return this.api('mfa-standing').then(function (data) {
            self.render(data);
        }).catch(function (e) {
            self.say(e.message, 'danger');
        });
    };

    /* data is api.php's decision payload: allowed, reason, message, mfa{...} */
    Card.prototype.render = function (data) {
        this.stopTimers();
        this.standing = data;
        var mfa = data.mfa || {};
        this.body.innerHTML = '';

        if (mfa.active && !this.wasActive && this.onPassed) {
            this.wasActive = true;
            this.onPassed(mfa);
        }
        this.wasActive = !!mfa.active;

        if (data.reason === 'not_epcs' || data.reason === 'not_verified') {
            this.setBadge('secondary', 'Not available');
            this.say(data.message, 'muted');
            return;
        }

        if (mfa.active) {
            this.renderActive(mfa);
        } else if (mfa.status === 'WAITING') {
            this.renderWaiting(mfa);
        } else {
            if (mfa.status === 'FAILED') {
                this.setBadge('danger', 'Not accepted');
                this.say(mfa.message || 'ID.me did not accept the code.', 'danger');
            } else if (mfa.status === 'EXPIRED') {
                this.setBadge('secondary', 'Expired');
                this.say('Your last two-factor session has expired. Start again to send controlled substances.');
            } else {
                this.setBadge('secondary', 'Not started');
                this.say('Not done yet in this session.');
            }
            if (this.autoStart && mfa.status !== 'FAILED') {
                this.showMethods();
                return;
            }
            this.renderStart(mfa.status === 'FAILED');
        }
    };

    Card.prototype.renderActive = function (mfa) {
        var self = this;
        this.setBadge('success', 'Passed');
        var tick = function () {
            var left = remaining(mfa.expires_at);
            self.say('Passed' + (mfa.method_name ? ' with ' + mfa.method_name : '')
                + '. Controlled substances can be sent for another ' + left
                + ' (until ' + localTime(mfa.expires_at) + ').', 'success');
            if (new Date(mfa.expires_at).getTime() <= Date.now()) {
                self.load();
            }
        };
        tick();
        this.timer = setInterval(tick, 1000);

        var again = el('button', 'btn btn-outline-secondary btn-sm', 'Start again');
        again.type = 'button';
        again.addEventListener('click', function () { self.showMethods(); });
        this.body.appendChild(again);
    };

    Card.prototype.renderWaiting = function (mfa) {
        var self = this;
        this.setBadge('warning', 'Waiting for you');
        var isPush = mfa.is_push;

        var tick = function () {
            var left = remaining(mfa.expires_at);
            self.say((isPush
                ? 'Accept the notification in your ID.me app. '
                : 'Enter the code from ' + (mfa.method_name || 'ID.me') + '. ')
                + 'This session expires in ' + left + '.', 'warning');
            if (new Date(mfa.expires_at).getTime() <= Date.now()) {
                self.load();
            }
        };
        tick();
        this.timer = setInterval(tick, 1000);

        if (isPush) {
            var waiting = el('div', 'small text-muted mb-2', 'Waiting for ID.me...');
            this.body.appendChild(waiting);
            var pollOnce = function () {
                self.api('mfa-check').then(function (data) {
                    if (data.mfa && data.mfa.status === 'WAITING' && !data.mfa.active) {
                        self.poll = setTimeout(pollOnce, POLL_MS);
                        return;
                    }
                    self.render(data);
                }).catch(function (e) {
                    waiting.textContent = e.message;
                    waiting.className = 'small text-danger mb-2';
                    self.poll = setTimeout(pollOnce, POLL_MS * 3);
                });
            };
            this.poll = setTimeout(pollOnce, POLL_MS);
        } else {
            this.body.appendChild(this.codeForm(mfa));
        }

        var other = el('button', 'btn btn-link btn-sm', 'Choose another method');
        other.type = 'button';
        other.addEventListener('click', function () { self.showMethods(); });
        this.body.appendChild(other);
    };

    Card.prototype.codeForm = function (mfa) {
        var self = this;
        var form = el('form', 'form-inline mb-2');
        var input = el('input', 'form-control form-control-sm mr-2');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.autocomplete = 'one-time-code';
        input.placeholder = 'Code from ' + (mfa.method_name || 'ID.me');
        input.required = true;
        input.maxLength = 12;
        var submit = el('button', 'btn btn-primary btn-sm', 'Verify code');
        submit.type = 'submit';
        form.appendChild(input);
        form.appendChild(submit);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submit.disabled = true;
            self.api('mfa-complete', { code: input.value }).then(function (data) {
                // A rejected code leaves the session open; render() brings
                // the box back for a new one.
                input.value = '';
                self.render(data);
            }).catch(function (err) {
                self.say(err.message, 'danger');
                submit.disabled = false;
            });
        });
        setTimeout(function () { input.focus(); }, 0);
        return form;
    };

    Card.prototype.renderStart = function (sessionStillOpen) {
        var self = this;
        if (sessionStillOpen && this.standing && this.standing.mfa && !this.standing.mfa.is_push) {
            // A rejected code does not end the session; try another code first.
            this.body.appendChild(this.codeForm(this.standing.mfa));
        }
        var start = el('button', 'btn btn-primary btn-sm', sessionStillOpen ? 'Start again' : 'Start two-factor authentication');
        start.type = 'button';
        start.addEventListener('click', function () { self.showMethods(); });
        this.body.appendChild(start);
    };

    Card.prototype.showMethods = function () {
        var self = this;
        this.stopTimers();
        this.body.innerHTML = '';
        this.say('Asking ID.me which methods you have set up...');

        this.api('mfa-methods').then(function (data) {
            var methods = data.methods || [];
            self.body.innerHTML = '';
            if (!methods.length) {
                self.say('ID.me lists no two-factor methods on your account. Add one at id.me, then try again.', 'danger');
                self.renderStart(false);
                return;
            }
            self.say('Choose how ID.me should confirm it is you.');

            var form = el('form');
            methods.forEach(function (m, i) {
                var id = 'erx-mfa-' + self.facilityId + '-' + i;
                var wrap = el('div', 'form-check');
                var radio = el('input', 'form-check-input');
                radio.type = 'radio';
                radio.name = 'method';
                radio.id = id;
                radio.value = m.uuid;
                if (i === 0) { radio.checked = true; }
                var label = el('label', 'form-check-label');
                label.htmlFor = id;
                label.innerHTML = '<strong>' + esc(m.name) + '</strong>'
                    + (m.label && m.label !== m.name ? ' <span class="text-muted">' + esc(m.label) + '</span>' : '')
                    + (m.isPush ? ' <span class="badge badge-info">push</span>' : '');
                wrap.appendChild(radio);
                wrap.appendChild(label);
                form.appendChild(wrap);
            });
            var go = el('button', 'btn btn-primary btn-sm mt-2', 'Continue');
            go.type = 'submit';
            form.appendChild(go);
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var picked = form.querySelector('input[name=method]:checked');
                if (!picked) { return; }
                go.disabled = true;
                self.say('Starting...');
                self.api('mfa-start', { methodUuid: picked.value }).then(function (data) {
                    self.render(data);
                }).catch(function (err) {
                    self.say(err.message, 'danger');
                    go.disabled = false;
                });
            });
            self.body.appendChild(form);
        }).catch(function (e) {
            self.say(e.message, 'danger');
            self.renderStart(false);
        });
    };

    window.ErxMfaCard = Card;

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.erx-mfa'), function (root) {
            // A card marked manual (the Send-time modal) is driven by its screen.
            if (!root.dataset.manual) {
                new Card(root).load();
            }
        });
    });
})();
