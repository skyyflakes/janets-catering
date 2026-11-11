/**
 * Automatic sign-out after inactivity - Janet's Quality Catering System
 *
 * Mouse movement, clicks, keys, scrolling and touches count as activity. The time of the last activity
 * is shared by every open tab, so working in one tab keeps the others signed in too. While the person
 * is active, the server is told at most once a minute, so its own timer (includes/auth_check.php)
 * agrees with this one. The server still signs the session out on its own if this script never runs.
 *
 * Settings come from the script tag: data-limit (seconds), data-warn (seconds of warning),
 * data-ping (activity URL) and data-logout (sign-out URL).
 */
(function () {
    var tag = document.currentScript;
    if (!tag) return;
    var LIMIT = (parseInt(tag.dataset.limit, 10) || 1800) * 1000;
    var WARN = (parseInt(tag.dataset.warn, 10) || 120) * 1000;
    var PING_EVERY = 60 * 1000;
    var PING_URL = tag.dataset.ping, LOGOUT_URL = tag.dataset.logout;
    var KEY = 'jq_idle_last', PING_KEY = 'jq_idle_ping';
    var memory = {}, leaving = false, box = null, countdown = null;

    // Private windows and blocked storage still work, for this tab alone.
    function read(key) { try { var v = localStorage.getItem(key); return v === null ? memory[key] || 0 : parseInt(v, 10) || 0; } catch (e) { return memory[key] || 0; } }
    function write(key, value) { memory[key] = value; try { localStorage.setItem(key, String(value)); } catch (e) { /* this tab only */ } }

    function signOut() {
        if (leaving) return;
        leaving = true;
        location.href = LOGOUT_URL;
    }

    function ping() {
        write(PING_KEY, Date.now());
        fetch(PING_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                // The server ended the session already (timed out or signed out elsewhere).
                if (r.redirected || r.status === 401 || r.status === 403 && !/json/.test(r.headers.get('Content-Type') || '')) signOut();
                return r.ok ? r.json() : null;
            })
            .then(function (data) { if (data === null || (data && data.ok !== true)) signOut(); })
            .catch(function () { /* offline: the timer here still applies */ });
    }

    var lastMark = 0;
    function activity() {
        var now = Date.now();
        if (now - lastMark < 1000) return; // mouse movement fires many times a second
        lastMark = now;
        if (now - read(KEY) >= LIMIT) { signOut(); return; } // came back too late: no new session time
        write(KEY, now);
        hideWarning();
        if (now - read(PING_KEY) >= PING_EVERY) ping();
    }

    function fmt(ms) {
        var s = Math.max(0, Math.ceil(ms / 1000));
        return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }
    function showWarning(left) {
        if (!box) {
            box = document.createElement('div');
            box.id = 'jqIdleWarning';
            box.setAttribute('role', 'alertdialog');
            box.setAttribute('aria-live', 'assertive');
            box.setAttribute('aria-labelledby', 'jqIdleTitle');
            box.style.cssText = 'position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(20,16,40,.55);';
            box.innerHTML = '<div style="background:var(--card-bg,#fff);color:var(--heading-color,#1A1A2E);border-radius:12px;max-width:380px;width:100%;padding:22px;box-shadow:0 10px 40px rgba(0,0,0,.3);font-family:inherit;text-align:center;">'
                + '<div id="jqIdleTitle" style="font-size:1.1rem;font-weight:700;margin-bottom:6px;">Still there?</div>'
                + '<p style="margin:0 0 16px;font-size:.92rem;">For your security you will be signed out in <strong id="jqIdleCount"></strong> because there has been no activity.</p>'
                + '<div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">'
                + '<button type="button" id="jqIdleStay" style="background:#7B5FC7;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer;">Stay signed in</button>'
                + '<button type="button" id="jqIdleLeave" style="background:transparent;color:inherit;border:1px solid #C8B8E8;border-radius:8px;padding:10px 18px;font-weight:600;cursor:pointer;">Sign out now</button></div></div>';
            document.body.appendChild(box);
            document.getElementById('jqIdleStay').addEventListener('click', function () { lastMark = 0; activity(); });
            document.getElementById('jqIdleLeave').addEventListener('click', signOut);
            document.getElementById('jqIdleStay').focus();
        }
        document.getElementById('jqIdleCount').textContent = fmt(left);
    }
    function hideWarning() {
        if (box) { box.remove(); box = null; }
    }

    function check() {
        if (leaving) return;
        var idle = Date.now() - read(KEY);
        if (idle >= LIMIT) { signOut(); return; }
        if (idle >= LIMIT - WARN) showWarning(LIMIT - idle); else hideWarning();
    }

    if (!read(KEY) || Date.now() - read(KEY) >= LIMIT) write(KEY, Date.now()); // this page load was activity
    ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'pointerdown', 'input'].forEach(function (ev) {
        document.addEventListener(ev, activity, { passive: true, capture: true });
    });
    window.addEventListener('scroll', activity, { passive: true, capture: true });
    // A phone or laptop that slept: check at once when the page is shown again.
    document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
    window.addEventListener('focus', check);
    window.addEventListener('storage', function (e) { if (e.key === KEY) check(); });
    countdown = setInterval(check, 1000);
})();
