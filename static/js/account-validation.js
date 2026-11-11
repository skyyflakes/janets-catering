/**
 * Account forms: 16-character password rule and live username / phone / email duplicate checks.
 * Mark up a form with data-account-form="create" or "profile". The server repeats every check.
 */
(function () {
    'use strict';
    var MIN_PASSWORD = 16;

    function feedback(input) {
        var host = input.closest('.jq-ph-group, .jq-pw-wrap, .input-group') || input;
        var el = host.parentNode.querySelector('.jq-field-error');
        if (!el) {
            el = document.createElement('small');
            el.className = 'jq-field-error';
            el.style.cssText = 'display:block;margin-top:4px;color:var(--jq-delete-text);font-weight:600;';
            host.parentNode.insertBefore(el, host.nextSibling);
        }
        return el;
    }
    function setError(input, message) {
        feedback(input).textContent = message || '';
        input.setCustomValidity(message || '');
    }

    function attachRemote(form, input, field, scope) {
        var timer = null, seq = 0;
        function check() {
            var value = input.value.trim(), mine = ++seq;
            if (!value) { setError(input, ''); return; }
            if (input.dataset.rule && window.JQRules && !window.JQRules.check(input)) { return; }
            var body = new URLSearchParams({ field: field, value: value, scope: scope });
            fetch('ajax/check_account_field.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (mine !== seq) return;
                    setError(input, d.ok ? '' : d.message);
                })
                .catch(function () { if (mine === seq) setError(input, ''); }); // the server still checks on submit
        }
        input.addEventListener('input', function () {
            if (input.dataset.rule && window.JQRules) { window.JQRules.check(input); } else { setError(input, ''); }
            clearTimeout(timer);
            timer = setTimeout(check, 400);
        });
        input.addEventListener('blur', check);
    }

    function attachPassword(form, pw, confirm) {
        function validate() {
            var len = pw.value.length;
            setError(pw, len > 0 && len < MIN_PASSWORD ? 'Password must be at least ' + MIN_PASSWORD + ' characters (' + len + ' so far).' : '');
            if (confirm) setError(confirm, confirm.value && confirm.value !== pw.value ? 'Passwords do not match.' : '');
        }
        pw.addEventListener('input', validate);
        if (confirm) confirm.addEventListener('input', validate);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-account-form]').forEach(function (form) {
            var scope = form.dataset.accountForm;
            var username = form.querySelector('[name="username"]');
            var phone = form.querySelector('[name="contact"], [name="contact_number"]');
            var email = form.querySelector('[name="email"]');
            if (username && scope === 'create') attachRemote(form, username, 'username', scope);
            if (phone) attachRemote(form, phone, 'phone', scope);
            if (email) attachRemote(form, email, 'email', scope);
        });
        document.querySelectorAll('form[data-password-form]').forEach(function (form) {
            var pw = form.querySelector('[name="password"], [name="new_password"]');
            if (pw) attachPassword(form, pw, form.querySelector('[name="confirm_password"]'));
        });
    });
})();
