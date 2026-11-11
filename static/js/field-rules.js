/**
 * Field rules for names, Philippine mobile numbers and postal codes.
 * Mark an input with data-rule="name", "ph-mobile" or "postal". The server repeats every check
 * (includes/validation.php). A ph-mobile box shows a fixed +63 and takes the 10 digits after it.
 *
 * Names and mobile numbers also refuse bad characters as they arrive, whether typed, pasted, dropped
 * or filled in by the browser: the box keeps its previous value and says why.
 */
window.JQRules = (function () {
    'use strict';

    var NAME = /^[A-Za-z]+(?: [A-Za-z]+)*$/;
    var MESSAGES = {
        name: 'Use letters and spaces only. Numbers and symbols are not allowed.',
        'ph-mobile': 'Enter exactly 10 digits after +63, starting with 9. Numbers only, for example +63 9123456789.',
        postal: 'Postal code must be exactly 4 digits, for example 1100.'
    };
    // What a box may hold while it is still being filled in.
    var IN_PROGRESS = {
        name: /^(?:[A-Za-z]+(?: [A-Za-z]+)* ?)?$/,
        'ph-mobile': /^(?:9\d{0,9})?$/
    };

    function phoneDigits(value) {
        return String(value || '').replace(/^\s*\+63\s*/, '').trim();
    }

    function messageFor(input) {
        var value = input.value.trim();
        if (value === '') { return ''; } // "required" is checked separately
        switch (input.dataset.rule) {
            case 'name':
                return NAME.test(value.replace(/\s+/g, ' ')) ? '' : MESSAGES.name;
            case 'ph-mobile':
                return /^9\d{9}$/.test(value) ? '' : MESSAGES['ph-mobile'];
            case 'postal':
                return /^\d{4}$/.test(value) ? '' : MESSAGES.postal;
        }
        return '';
    }

    function errorElement(input) {
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
        errorElement(input).textContent = message || '';
        input.setCustomValidity(message || '');
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
    }

    /** Sets or clears the message on one input; true when the value is acceptable. */
    function check(input) {
        if (input.dataset.rule === 'ph-mobile') { input.value = phoneDigits(input.value); }
        var message = messageFor(input);
        setError(input, message);
        return message === '';
    }

    /** Checks every marked input inside an element, and focuses the first problem. */
    function validateIn(root) {
        var ok = true;
        root.querySelectorAll('[data-rule]').forEach(function (input) {
            if (input.dataset.rule === 'name') { input.value = input.value.trim().replace(/\s+/g, ' '); }
            if (!check(input) && ok) {
                ok = false;
                input.focus();
            }
        });
        return ok;
    }

    function guarded(input) {
        return input && input.dataset && IN_PROGRESS.hasOwnProperty(input.dataset.rule);
    }

    function allowed(input, value) {
        return IN_PROGRESS[input.dataset.rule].test(value);
    }

    /** The specific reason a value was refused. */
    function refusalFor(input, value) {
        if (input.dataset.rule === 'name') { return MESSAGES.name; }
        if (/[^\d]/.test(value)) { return 'Numbers only. Letters and symbols are not allowed.'; }
        if (value !== '' && value.charAt(0) !== '9') { return 'The contact number must start with 9.'; }
        return MESSAGES['ph-mobile'];
    }

    function remember(input) {
        input._jqPrev = { value: input.value, start: input.selectionStart, end: input.selectionEnd };
    }

    function refuse(input, attempted) {
        var prev = input._jqPrev || { value: '', start: 0, end: 0 };
        input.value = prev.value;
        try { input.setSelectionRange(prev.start, prev.end); } catch (err) { /* not every browser allows this */ }
        input.setCustomValidity(messageFor(input));
        errorElement(input).textContent = refusalFor(input, attempted);
    }

    function prepare(input) {
        if (input.dataset.ruleReady) { return; }
        input.dataset.ruleReady = '1';
        if (input.dataset.rule === 'ph-mobile') {
            input.value = phoneDigits(input.value);
            input.maxLength = 10;
            input.inputMode = 'numeric';
            input.placeholder = '9123456789';
            var group = document.createElement('div');
            group.className = 'jq-ph-group';
            input.parentNode.insertBefore(group, input);
            var prefix = document.createElement('span');
            prefix.className = 'jq-ph-prefix';
            prefix.textContent = '+63';
            group.appendChild(prefix);
            group.appendChild(input);
        } else if (input.dataset.rule === 'postal') {
            input.maxLength = 4;
            input.inputMode = 'numeric';
        }
    }

    /** Puts text where the caret is, or returns the refused result. A pasted +63 prefix is accepted. */
    function insertText(input, text) {
        var clean = String(text).trim();
        if (input.dataset.rule === 'ph-mobile') { clean = phoneDigits(clean).replace(/\s+/g, ''); }
        var start = input.selectionStart, end = input.selectionEnd;
        remember(input);
        var result = input.value.slice(0, start) + clean + input.value.slice(end);
        if (input.maxLength > 0 && result.length > input.maxLength) {
            refuse(input, result);
            return;
        }
        if (!allowed(input, result)) {
            refuse(input, result);
            return;
        }
        input.value = result;
        input.setSelectionRange(start + clean.length, start + clean.length);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-rule]').forEach(prepare);
    });

    // Runs before each edit, so a refused edit can be undone exactly.
    document.addEventListener('beforeinput', function (e) {
        if (guarded(e.target)) { remember(e.target); }
    }, true);
    document.addEventListener('focusin', function (e) {
        if (guarded(e.target)) { remember(e.target); }
    });

    document.addEventListener('paste', function (e) {
        if (!guarded(e.target) || !e.clipboardData) { return; }
        e.preventDefault();
        insertText(e.target, e.clipboardData.getData('text'));
    }, true);
    document.addEventListener('drop', function (e) {
        if (!guarded(e.target) || !e.dataTransfer) { return; }
        e.preventDefault();
        e.target.focus();
        insertText(e.target, e.dataTransfer.getData('text'));
    }, true);

    // Phone keyboards build words in stages, so those are filtered when each word is finished.
    document.addEventListener('compositionend', function (e) {
        var input = e.target;
        if (!guarded(input)) { return; }
        var value = input.value;
        var clean = input.dataset.rule === 'name'
            ? value.replace(/[^A-Za-z ]/g, '').replace(/^ +/, '').replace(/ {2,}/g, ' ')
            : value.replace(/\D/g, '');
        if (clean !== value) { input.value = clean; errorElement(input).textContent = refusalFor(input, value); }
    });

    document.addEventListener('input', function (e) {
        var input = e.target;
        if (!(input.dataset && input.dataset.rule)) { return; }
        if (guarded(input) && !e.isComposing && !allowed(input, input.value)) {
            var prev = input._jqPrev;
            var hadBadValue = prev && !allowed(input, prev.value);        // an older record can still be corrected
            var nameDeletion = input.dataset.rule === 'name' && /^delete/.test(e.inputType || '');
            if (!hadBadValue && !nameDeletion) {
                refuse(input, input.value);
                return;
            }
        }
        check(input);
        if (guarded(input)) { remember(input); }
    });

    document.addEventListener('blur', function (e) {
        var input = e.target;
        if (!(input.dataset && input.dataset.rule)) { return; }
        if (input.dataset.rule === 'name') { input.value = input.value.trim().replace(/\s+/g, ' '); }
        check(input);
    }, true);

    // Forms marked novalidate reach this handler too. Capture runs before the page's own submit handlers.
    document.addEventListener('submit', function (e) {
        if (e.target.querySelector && !validateIn(e.target)) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    return { check: check, validateIn: validateIn, setError: setError, phoneDigits: phoneDigits };
})();
