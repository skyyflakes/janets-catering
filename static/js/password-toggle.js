/**
 * Adds an eye / eye-off button to every password field that does not already have one.
 * Eye = click to show, eye-off = click to hide. Only the input type changes, never the value.
 */
(function () {
    'use strict';
    function enhance(input) {
        if (input.dataset.pwToggle || input.closest('.input-group')) return; // login and profile have their own
        input.dataset.pwToggle = '1';
        var wrap = document.createElement('div');
        wrap.className = 'jq-pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'jq-pw-eye';
        btn.setAttribute('aria-label', 'Show password');
        btn.title = 'Show password';
        btn.innerHTML = '<i class="bx bx-show"></i>';
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.firstChild.className = 'bx ' + (show ? 'bx-hide' : 'bx-show');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.title = show ? 'Hide password' : 'Show password';
        });
        wrap.appendChild(btn);
    }
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="password"]').forEach(enhance);
    });
})();
