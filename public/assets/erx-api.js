/*
 * Shared by the prescribing screen and the pharmacy request inbox.
 *
 * Every call goes through api.php, which enforces the ACL and CSRF token.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var cfg = window.erxConfig || {};

    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function call(action, params, method) {
        var body = new URLSearchParams(params || {});
        body.set('action', action);
        body.set('csrf_token', cfg.csrfToken);
        if (!body.has('pid')) {
            body.set('pid', cfg.pid || '');
        }

        var opts = { method: method || 'POST', credentials: 'same-origin' };
        if (opts.method === 'POST') {
            opts.body = body;
        }
        var url = cfg.apiUrl + (opts.method === 'GET' ? '?' + body.toString() : '');

        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    // error is what the LogiCoy backend said went wrong, in
                    // its own words; detail is a hint the module adds to some
                    // refusals (where to complete ID.me, say).
                    var err = new Error((data.error || 'Request failed (' + res.status + ')')
                        + (data.detail ? ' ' + data.detail : ''));
                    err.errors = data.errors;
                    err.status = res.status;
                    // The backend's error code, for support.
                    err.code = data.code;
                    // Why a controlled substance send was refused, so the
                    // screen can ask for the MFA code instead of just saying no.
                    err.reason = data.reason;
                    throw err;
                }
                return data;
            });
        });
    }

    /*
     * Client-side paging for a rendered list. Every list the module shows is
     * loaded whole (a patient's sent scripts, the facility's open requests),
     * so paging only decides which slice is drawn and adds Previous / Next
     * under it. state.page is kept by the caller, so a reload lands on the
     * same page; it is clamped here when the list has shrunk.
     *
     *   var p = erxApi.paged(items, state, 10);
     *   box.innerHTML = p.items.map(renderOne).join('') + p.controls;
     */
    function paged(items, state, pageSize) {
        var total = items.length;
        var pages = Math.max(1, Math.ceil(total / pageSize));
        state.page = Math.min(Math.max(0, state.page || 0), pages - 1);
        var start = state.page * pageSize;
        var end = Math.min(start + pageSize, total);

        return {
            items: items.slice(start, end),
            controls: total <= pageSize ? '' : '<div class="erx-pager">'
                + '<span class="small text-muted">' + (start + 1) + '\u2013' + end + ' of ' + total + '</span>'
                + '<div class="btn-group btn-group-sm ml-auto">'
                + '<button type="button" class="btn btn-outline-secondary" data-pg="prev"'
                + (state.page === 0 ? ' disabled' : '') + '>&lsaquo; Previous</button>'
                + '<button type="button" class="btn btn-outline-secondary" data-pg="next"'
                + (state.page >= pages - 1 ? ' disabled' : '') + '>Next &rsaquo;</button>'
                + '</div></div>'
        };
    }

    // Turns clicks on a list's Previous / Next into a page change and a
    // redraw. Returns true when the click was a pager button, so a list's
    // own click handler can leave it alone.
    function pagerClick(e, state, rerender) {
        var btn = e.target.closest && e.target.closest('.erx-pager button[data-pg]');
        if (!btn) {
            return false;
        }
        e.preventDefault();
        state.page = (state.page || 0) + (btn.dataset.pg === 'next' ? 1 : -1);
        rerender();
        return true;
    }

    window.erxApi = { call: call, esc: esc, paged: paged, pagerClick: pagerClick };
})();
