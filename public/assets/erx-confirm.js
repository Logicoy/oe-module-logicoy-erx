/*
 * An in-page confirmation dialog, in place of window.confirm().
 *
 * Shared by the prescribing screen, the pharmacy request inbox and the setup
 * screen. Uses the styles in erx-modal.css. Every string is set as text, so a
 * pharmacy or drug name from the backend cannot inject markup.
 *
 *   window.erxConfirm({ title, message, confirmText, cancelText, danger })
 *       .then(function (ok) { ... });
 *
 * Resolves true on the confirm button, false on Cancel, Escape or a click on
 * the backdrop.
 *
 * Server-rendered forms ask for it with attributes instead of script, on the
 * submit button or on the form itself:
 *
 *   data-erx-confirm="Message"  data-erx-confirm-title="..."
 *   data-erx-confirm-ok="..."   data-erx-confirm-cancel="..."
 *   data-erx-confirm-danger
 *
 * The submit is held until the answer is yes, then repeated with the same
 * button, so its name, value, formtarget and formnovalidate still apply.
 *
 * license  https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
(function () {
    'use strict';

    var modal = null;
    var settle = null;

    function build() {
        modal = document.createElement('div');
        modal.className = 'erx-modal erx-confirm';
        modal.hidden = true;
        modal.setAttribute('role', 'alertdialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'erx-confirm-title');
        modal.setAttribute('aria-describedby', 'erx-confirm-message');
        modal.innerHTML = '<div class="erx-modal-dialog">'
            + '<div class="erx-modal-header"><strong id="erx-confirm-title"></strong></div>'
            + '<p id="erx-confirm-message" class="mb-0"></p>'
            + '<div class="erx-modal-footer">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary mr-2" data-answer="no"></button>'
            + '<button type="button" class="btn btn-sm" data-answer="yes"></button>'
            + '</div></div>';
        document.body.appendChild(modal);

        modal.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-answer]');
            if (btn) {
                close(btn.dataset.answer === 'yes');
            } else if (e.target === modal) {
                close(false);
            }
        });
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                close(false);
            } else if (e.key === 'Tab') {
                // Two buttons: keep focus inside the dialog.
                var buttons = modal.querySelectorAll('button');
                var first = buttons[0];
                var last = buttons[buttons.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
    }

    function close(answer) {
        if (!settle) { return; }
        var done = settle;
        settle = null;
        modal.hidden = true;
        done(answer);
    }

    function erxConfirm(opts) {
        opts = opts || {};
        if (!modal) { build(); }
        // A second prompt while one is open answers the first with no.
        close(false);

        var returnFocus = document.activeElement;
        var yes = modal.querySelector('[data-answer=yes]');
        var no = modal.querySelector('[data-answer=no]');

        modal.querySelector('#erx-confirm-title').textContent = opts.title || 'Please confirm';
        modal.querySelector('#erx-confirm-message').textContent = opts.message || '';
        yes.textContent = opts.confirmText || 'OK';
        no.textContent = opts.cancelText || 'Cancel';
        yes.className = 'btn btn-sm ' + (opts.danger ? 'btn-danger' : 'btn-primary');

        return new Promise(function (resolve) {
            settle = function (answer) {
                if (returnFocus && typeof returnFocus.focus === 'function') { returnFocus.focus(); }
                resolve(answer);
            };
            modal.hidden = false;
            // Starts on Cancel for anything that cannot be undone, so a stray
            // Enter does not confirm it.
            (opts.danger ? no : yes).focus();
        });
    }

    window.erxConfirm = erxConfirm;

    // The form being resubmitted after a yes, so its submit is let through.
    var confirmed = null;

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form === confirmed) { return; }

        var submitter = e.submitter || null;
        var source = submitter && submitter.hasAttribute('data-erx-confirm') ? submitter
            : form.hasAttribute('data-erx-confirm') ? form : null;
        if (!source) { return; }
        // Held, so no other submit handler acts on it until the answer is yes.
        e.preventDefault();
        e.stopPropagation();

        erxConfirm({
            title: source.getAttribute('data-erx-confirm-title'),
            message: source.getAttribute('data-erx-confirm'),
            confirmText: source.getAttribute('data-erx-confirm-ok'),
            cancelText: source.getAttribute('data-erx-confirm-cancel'),
            danger: source.hasAttribute('data-erx-confirm-danger')
        }).then(function (ok) {
            if (!ok) { return; }
            // Runs in the same task as the click on the dialog's button, so a
            // formtarget="_blank" submit still counts as user-initiated.
            confirmed = form;
            try {
                if (form.requestSubmit) {
                    form.requestSubmit(submitter || undefined);
                } else {
                    // Older browsers: carry the button's name and value by hand.
                    if (submitter && submitter.name) {
                        var carried = document.createElement('input');
                        carried.type = 'hidden';
                        carried.name = submitter.name;
                        carried.value = submitter.value;
                        form.appendChild(carried);
                    }
                    if (submitter && submitter.formTarget) { form.target = submitter.formTarget; }
                    form.submit();
                }
            } finally {
                confirmed = null;
            }
        });
    }, true);
})();
