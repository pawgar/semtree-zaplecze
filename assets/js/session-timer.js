// ════════════════════════════════════════════════════════════════
//  Session timer — countdown to absolute 24h session expiry.
//  Updates the .session-timer pill in the topbar.
//  Re-syncs with server every 60s, ticks locally every second.
// ════════════════════════════════════════════════════════════════
(function () {
    const PILL_ID = 'sessionTimerPill';
    const SYNC_INTERVAL = 60_000; // 60s
    const WARNING_AT  = 30 * 60;  // 30 min
    const DANGER_AT   = 5 * 60;   // 5 min

    let remaining = 0;
    let lastSync = 0;

    function format(s) {
        if (s <= 0) return '00:00';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        if (h > 0) {
            return h + 'h ' + String(m).padStart(2, '0') + 'm';
        }
        return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
    }

    function render() {
        const pill = document.getElementById(PILL_ID);
        if (!pill) return;
        const label = pill.querySelector('.session-timer-label');
        if (label) label.textContent = format(remaining);
        pill.classList.remove('is-warning', 'is-danger');
        if (remaining <= DANGER_AT) pill.classList.add('is-danger');
        else if (remaining <= WARNING_AT) pill.classList.add('is-warning');
    }

    async function sync() {
        try {
            const r = await fetch('/api/session-info.php', {credentials: 'same-origin'});
            if (r.status === 401) {
                // Session already gone — force a logout redirect
                window.location.href = 'index.php?page=logout';
                return;
            }
            const j = await r.json();
            if (j.expired) {
                window.location.href = 'index.php?page=logout';
                return;
            }
            if (typeof j.remaining === 'number') {
                remaining = j.remaining;
                lastSync = Date.now();
                render();
            }
        } catch (e) {
            // network blip — keep ticking locally
        }
    }

    function tick() {
        if (remaining > 0) {
            remaining -= 1;
            render();
            if (remaining <= 0) {
                window.location.href = 'index.php?page=logout';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById(PILL_ID)) return;
        sync();
        setInterval(tick, 1000);
        setInterval(sync, SYNC_INTERVAL);
    });
})();
