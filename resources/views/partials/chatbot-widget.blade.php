<div id="chatbot-widget" class="chatbot-widget">
    <button type="button" id="chatbot-toggle" class="chatbot-toggle" aria-label="Chat with Davo, your Davao travel buddy" aria-controls="chatbot-panel" aria-expanded="false">
        <span class="chatbot-avatar" aria-hidden="true">
            <img class="chatbot-rest-pose" src="{{ asset('images/davo.webp') }}" alt="" width="512" height="512">
            <img class="chatbot-wave-pose" src="{{ asset('images/davo-wave-green.webp') }}" alt="" width="374" height="504">
        </span>
        <span class="chatbot-toggle-badge" aria-hidden="true"><x-icon name="chat" /></span>
    </button>

    <div id="chatbot-panel" class="chatbot-panel" hidden role="region" aria-labelledby="chatbot-title">
        <div class="chatbot-panel-head">
            <span class="chatbot-avatar chatbot-head-avatar" aria-hidden="true"><img src="{{ asset('images/davo.webp') }}" alt="" width="512" height="512"></span>
            <div class="chatbot-heading">
                <strong id="chatbot-title">Davo</strong>
                <div class="sub">Your Davao travel buddy</div>
            </div>
            <button type="button" id="chatbot-reset" class="chatbot-icon-btn" aria-label="Start a new chat">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 11a8 8 0 10-2.3 5.7"/><path d="M20 4v7h-7"/></svg>
            </button>
            <button type="button" id="chatbot-close" class="chatbot-icon-btn" aria-label="Close chat">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
        <div id="chatbot-messages" class="chatbot-messages" role="log" aria-label="Conversation with Davo" aria-live="polite">
            <div class="chatbot-row chatbot-intro">
                <span class="chatbot-avatar chatbot-mini-avatar" aria-hidden="true"><img src="{{ asset('images/davo.webp') }}" alt="" width="64" height="64" loading="lazy"></span>
                <div class="chatbot-msg chatbot-msg-bot"><strong>Hi, I'm Davo!</strong> 👋 What would you like to explore?</div>
            </div>
            <div class="chatbot-suggest" id="chatbot-suggest">
                <div class="chatbot-suggest-label">Try asking:</div>
                <button type="button" class="chatbot-chip" data-ask="Things to do this weekend"><span aria-hidden="true">🌿</span> Things to do this weekend</button>
                <button type="button" class="chatbot-chip" data-ask="Where to stay near the airport"><span aria-hidden="true">🏨</span> Where to stay near the airport</button>
                <button type="button" class="chatbot-chip" data-ask="Must-try local food"><span aria-hidden="true">🍽️</span> Must-try local food</button>
                <button type="button" class="chatbot-chip" data-ask="Plan a 3-day trip"><span aria-hidden="true">🗺️</span> Plan a 3-day trip</button>
            </div>
        </div>
        <form id="chatbot-form" class="chatbot-form">
            <div class="chatbot-compose">
                <input type="text" id="chatbot-input" aria-label="Your message to Davo" placeholder="Ask Davo a question&hellip;" maxlength="500" autocomplete="off">
                <button type="submit" id="chatbot-send" class="chatbot-send" aria-label="Send message" disabled>
                    <x-icon name="send" />
                </button>
            </div>
            <p class="chatbot-disclaimer">Davo is an AI assistant and can make mistakes. Check prices and hours before you go.</p>
        </form>
    </div>
</div>

<style>
    .chatbot-widget { position: fixed; right: 20px; bottom: 20px; z-index: 200; }
    .chatbot-toggle {
        position: relative; width: 84px; height: 84px; padding: 7px; border-radius: 50%; border: 0; cursor: pointer;
        background: var(--white); color: var(--white); display: flex; align-items: center; justify-content: center;
        box-shadow: 0 8px 24px rgba(0,0,0,.2); transition: transform .15s ease, box-shadow .15s ease;
    }
    .chatbot-toggle:hover { transform: scale(1.05); box-shadow: 0 10px 28px rgba(0,0,0,.26); }
    .chatbot-avatar { position: relative; display: block; width: 100%; height: 100%; overflow: hidden; border-radius: 50%; background: #fff5e5; flex-shrink: 0; }
    .chatbot-avatar img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center 45%; pointer-events: none; }
    /* The launcher: a white disc, a green ring around the picture, and a green chat badge on its lower-right edge. */
    .chatbot-toggle .chatbot-avatar { border: 4px solid var(--primary); box-sizing: border-box; }
    .chatbot-toggle .chatbot-wave-pose { display: none; position: absolute; inset: 0; object-fit: contain; object-position: center; transform-origin: 50% 85%; }
    .chatbot-toggle.is-waving .chatbot-rest-pose { visibility: hidden; }
    .chatbot-toggle.is-waving .chatbot-wave-pose { display: block; animation: davo-hello 1.2s ease-in-out; }
    @keyframes davo-hello {
        0%, 100% { transform: rotate(0); }
        20%, 60% { transform: rotate(-7deg); }
        40%, 80% { transform: rotate(7deg); }
    }
    .chatbot-toggle-badge { position: absolute; right: -4px; bottom: -4px; width: 36px; height: 36px; display: grid; place-items: center; border-radius: 50%; background: var(--primary); border: 4px solid var(--white); box-shadow: 0 2px 8px rgba(0,0,0,.18); }
    .chatbot-toggle-badge svg { width: 17px; height: 17px; }
    .chatbot-widget button:focus-visible { outline: 3px solid #e98543; outline-offset: 3px; }
    .chatbot-head-avatar { width: 48px; height: 48px; margin-right: 12px; box-shadow: 0 0 0 2px rgba(255,255,255,.9); }
    .chatbot-heading { flex: 1; min-width: 0; }
    .chatbot-heading strong { font-size: 1.05rem; }

    .chatbot-panel {
        position: absolute; right: 0; bottom: 96px; width: 380px; max-width: calc(100vw - 40px);
        background: var(--white); border: 1px solid var(--border); border-radius: 20px;
        box-shadow: 0 14px 40px rgba(0,0,0,.2); display: flex; flex-direction: column; overflow: hidden;
        max-height: min(600px, calc(100dvh - 136px));
    }
    /* `display: flex` above has the same specificity as the browser's own
       `[hidden] { display: none }` rule, and an author rule wins that tie
       regardless of source order -- so without this, the panel's `hidden`
       attribute was cosmetic and it rendered open on every fresh page load,
       every time, until a click on #chatbot-toggle set an inline style. */
    .chatbot-panel[hidden] { display: none; }
    .chatbot-panel-head {
        display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
        padding: 16px 14px 16px 18px; background: var(--primary); color: var(--white);
    }
    .chatbot-panel-head .sub { color: rgba(255,255,255,.88); font-size: .78rem; }
    .chatbot-icon-btn {
        background: none; border: none; color: var(--white); cursor: pointer; flex-shrink: 0;
        width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 50%;
    }
    .chatbot-icon-btn:hover, .chatbot-icon-btn:active { background: rgba(255,255,255,.18); }
    .chatbot-icon-btn:focus-visible { outline-color: #fff; }

    .chatbot-messages { flex: 1 1 260px; overflow-y: auto; padding: 16px 16px 8px; display: flex; flex-direction: column; gap: 12px; min-height: 0; }
    .chatbot-row { display: flex; align-items: flex-end; gap: 8px; }
    .chatbot-mini-avatar { width: 28px; height: 28px; flex-shrink: 0; }
    .chatbot-msg { font-size: .9rem; line-height: 1.5; padding: 11px 14px; border-radius: 16px; max-width: 86%; white-space: pre-line; }
    .chatbot-msg-bot { background: #fff4e1; color: var(--ink); border: 1px solid #f7e7c8; border-bottom-left-radius: 4px; }
    .chatbot-msg-user { background: var(--primary); color: var(--white); align-self: flex-end; border-bottom-right-radius: 4px; }
    .chatbot-msg a { color: var(--primary-dark); }
    .chatbot-msg-user a { color: var(--white); }
    .chatbot-row .chatbot-msg-bot { max-width: calc(100% - 36px); }

    .chatbot-suggest { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; padding-left: 36px; }
    .chatbot-suggest[hidden] { display: none; }
    .chatbot-suggest-label { font-size: .78rem; font-weight: 600; color: var(--muted, #5b6b64); }
    .chatbot-chip {
        font: inherit; font-size: .86rem; font-weight: 600; color: var(--primary-dark); background: var(--white);
        border: 1.5px solid var(--primary); border-radius: 999px; padding: 8px 14px; cursor: pointer; text-align: left;
        transition: background .12s ease;
    }
    .chatbot-chip:hover { background: var(--primary-light); }

    /* Listing cards under an answer. */
    .chatbot-cards { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; white-space: normal; }
    .chatbot-card {
        display: flex; gap: 10px; align-items: center; padding: 8px; background: var(--white); border: 1px solid #efe1c4;
        border-radius: 14px; text-decoration: none; color: var(--ink);
    }
    .chatbot-card:hover { border-color: var(--primary); }
    .chatbot-card-img { width: 58px; height: 58px; border-radius: 10px; object-fit: cover; flex-shrink: 0; background: #e9efe9; }
    .chatbot-card-body { min-width: 0; display: flex; flex-direction: column; gap: 1px; }
    .chatbot-card-name { font-weight: 700; font-size: .9rem; line-height: 1.25; }
    .chatbot-card-where { font-size: .76rem; color: var(--muted, #5b6b64); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chatbot-card-meta { font-size: .8rem; font-weight: 700; color: var(--primary-dark); }

    /* The way into another page that an answer points at, e.g. the trip planner. */
    .chatbot-msg a.chatbot-action {
        display: inline-block; margin-top: 10px; padding: 9px 16px; border-radius: 999px; background: var(--primary);
        color: var(--white); font-weight: 700; font-size: .86rem; text-decoration: none; white-space: normal;
    }
    .chatbot-msg a.chatbot-action:hover { background: var(--primary-dark); }

    .chatbot-typing { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 4px; padding: 14px 16px; }
    .chatbot-typing-label { margin-right: 6px; font-size: .82rem; }
    .chatbot-thinking .chatbot-mini-avatar img { animation: davo-think 2s ease-in-out infinite; transform-origin: 50% 80%; }
    @keyframes davo-think { 0%, 100% { transform: rotate(0); } 50% { transform: rotate(-8deg); } }
    .chatbot-retry { display: block; margin-top: 10px; padding: 8px 14px; border: 1px solid var(--primary); border-radius: 999px; background: var(--white); color: var(--primary-dark); font: inherit; font-weight: 600; cursor: pointer; }
    .chatbot-retry:hover { background: var(--primary-light); }
    .chatbot-icon-btn:disabled { opacity: .5; cursor: default; }
    .chatbot-typing i { width: 7px; height: 7px; border-radius: 50%; background: #c9b690; animation: chatbot-blink 1s infinite ease-in-out; }
    .chatbot-typing i:nth-child(2) { animation-delay: .15s; }
    .chatbot-typing i:nth-child(3) { animation-delay: .3s; }
    @keyframes chatbot-blink { 0%, 80%, 100% { opacity: .3; } 40% { opacity: 1; } }

    .chatbot-form { padding: 12px 14px 12px; border-top: 1px solid var(--border); flex-shrink: 0; }
    .chatbot-compose { display: flex; gap: 8px; align-items: center; }
    .chatbot-compose input {
        flex: 1; min-width: 0; border: 1.5px solid #eadfc8; border-radius: 14px; padding: 12px 14px; font-size: 1rem; background: #fffdf8;
    }
    .chatbot-compose input:focus { outline: none; border-color: var(--primary); }
    .chatbot-send {
        flex-shrink: 0; width: 46px; height: 46px; border-radius: 14px; border: 0; display: grid; place-items: center; cursor: pointer;
        background: var(--primary); color: var(--white); transition: background .12s ease;
    }
    .chatbot-send:hover:not(:disabled) { background: var(--primary-dark); }
    .chatbot-send:disabled { background: #e6dcc6; color: #8c836f; cursor: default; }
    .chatbot-send svg { width: 20px; height: 20px; }
    .chatbot-disclaimer { margin: 8px 4px 0; text-align: center; font-size: .72rem; line-height: 1.35; color: var(--muted, #6b7a73); }

    @media (max-width: 480px) {
        .chatbot-panel { width: calc(100vw - 32px); right: -4px; }
    }

    /* Below 900px, listing detail pages show a full-width sticky CTA bar
       (.sticky-cta, ~72px tall) pinned to the bottom of the viewport. Left at
       its default 20px offset, the chat bubble's higher z-index sits on top
       of that bar's right-hand button and silently intercepts taps meant for
       it. Raising the widget above the bar's height keeps the two apart. */
    @media (max-width: 900px) {
        .chatbot-widget { bottom: 92px; }
        .chatbot-panel { max-height: calc(100dvh - 208px); }
    }
    @media (prefers-reduced-motion: reduce) {
        .chatbot-toggle.is-waving .chatbot-wave-pose { animation: none; }
        .chatbot-toggle { transition: none; } .chatbot-toggle:hover { transform: none; }
        .chatbot-typing i { animation: none; opacity: .7; }
        .chatbot-thinking .chatbot-mini-avatar img { animation: none; }
    }
</style>

<script>
(function () {
    // Event delegation on document/window instead of cached element references,
    // so open/close can never break due to element-lookup timing or stale refs
    // — every handler re-queries the DOM fresh at the moment of the event.

    function getPanel() { return document.getElementById('chatbot-panel'); }
    function getWidget() { return document.getElementById('chatbot-widget'); }
    var AVATAR = @json(asset('images/davo.webp'));
    var greeting = null; // the opening message and chips, kept so "new chat" can put them back
    var waveTimer;
    var lastWave = 0;
    function waveHello() {
        var toggle = document.getElementById('chatbot-toggle');
        var pose = toggle && toggle.querySelector('.chatbot-wave-pose');
        // Keep the familiar portrait if the optional pose has not loaded.
        if (!pose || !pose.complete || !pose.naturalWidth || Date.now() - lastWave < 4000) return;
        lastWave = Date.now();
        toggle.classList.add('is-waving');
        clearTimeout(waveTimer);
        waveTimer = setTimeout(function () { toggle.classList.remove('is-waving'); }, 1200);
    }
    var launcher = document.getElementById('chatbot-toggle');
    if (launcher) {
        launcher.addEventListener('pointerenter', function (event) {
            if (event.pointerType === 'mouse' && getPanel().hidden) waveHello();
        });
        launcher.addEventListener('focus', function () {
            if (launcher.matches(':focus-visible') && getPanel().hidden) waveHello();
        });
    }

    function setOpen(open) {
        var panel = getPanel();
        if (!panel) return;
        panel.hidden = !open;
        panel.style.display = open ? 'flex' : 'none';
        var toggle = document.getElementById('chatbot-toggle');
        if (toggle) toggle.setAttribute('aria-expanded', String(open));
        if (!open && panel.contains(document.activeElement) && toggle) toggle.focus();
        if (open) {
            waveHello();
            var input = document.getElementById('chatbot-input');
            if (input) input.focus();
        } else if (toggle) {
            clearTimeout(waveTimer);
            toggle.classList.remove('is-waving');
        }
    }

    document.addEventListener('click', function (e) {
        var toggleEl = e.target.closest && e.target.closest('#chatbot-toggle');
        var closeEl = e.target.closest && e.target.closest('#chatbot-close');
        var panel = getPanel();
        var widget = getWidget();
        if (!panel || !widget) return;

        if (toggleEl) {
            setOpen(panel.hidden);
            return;
        }
        if (closeEl) {
            setOpen(false);
            return;
        }

        var chip = e.target.closest && e.target.closest('.chatbot-chip');
        if (chip) {
            send(chip.getAttribute('data-ask'));
            return;
        }
        if (e.target.closest && e.target.closest('#chatbot-reset')) {
            resetChat();
            return;
        }

        if (!panel.hidden && !widget.contains(e.target)) {
            setOpen(false);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        var panel = getPanel();
        if (panel && e.key === 'Escape' && !panel.hidden) setOpen(false);
    });

    document.addEventListener('input', function (e) {
        if (e.target.id !== 'chatbot-input') return;
        var sendBtn = document.getElementById('chatbot-send');
        if (sendBtn) sendBtn.disabled = e.target.value.trim() === '';
    });

    document.addEventListener('submit', function (e) {
        if (e.target.id !== 'chatbot-form') return;
        e.preventDefault();
        var input = document.getElementById('chatbot-input');
        send(input.value);
    });

    function resetChat() {
        var messages = document.getElementById('chatbot-messages');
        if (!messages) return;
        if (greeting === null) return; // nothing has been said yet
        messages.innerHTML = greeting;
        greeting = null;
        var input = document.getElementById('chatbot-input');
        if (input) { input.value = ''; input.focus(); }
    }

    function send(raw, retryRow) {
        var input = document.getElementById('chatbot-input');
        var sendBtn = document.getElementById('chatbot-send');
        var messages = document.getElementById('chatbot-messages');
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        csrfToken = csrfToken ? csrfToken.content : '';

        var text = (raw || '').trim();
        if (!text || input.disabled) return;

        if (greeting === null) greeting = messages.innerHTML;
        var suggest = document.getElementById('chatbot-suggest');
        if (suggest) suggest.hidden = true;

        messages.querySelectorAll('.chatbot-retry').forEach(function (button) { button.remove(); });
        if (retryRow) retryRow.remove();
        else appendMessage(messages, text, 'user');
        input.value = '';
        input.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        var typing = appendTyping(messages);
        var resetBtn = document.getElementById('chatbot-reset');
        if (resetBtn) resetBtn.disabled = true;
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 30000);

        fetch('{{ route('chatbot.respond') }}', {
            signal: controller.signal,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ message: text }),
        })
            .then(function (res) {
                if (!res.ok) throw new Error('Chat request failed');
                return res.json();
            })
            .then(function (data) {
                if (!data || typeof data.response !== 'string' || !data.response.trim()) throw new Error('Empty reply');
                typing.remove();
                appendMessage(messages, data.response, 'bot', data.cards || [], data.action);
            })
            .catch(function () {
                typing.remove();
                var failure = appendMessage(messages, "Sorry, I couldn't get a reply right now. You can try again.", 'bot');
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'chatbot-retry';
                retry.textContent = 'Try again';
                retry.addEventListener('click', function () { send(text, failure.parentNode); });
                failure.appendChild(retry);
                messages.scrollTop = messages.scrollHeight;
            })
            .finally(function () {
                clearTimeout(timeout);
                typing.remove();
                input.disabled = false;
                if (sendBtn) sendBtn.disabled = input.value.trim() === '';
                if (resetBtn) resetBtn.disabled = false;
                if (!getPanel().hidden && getWidget().contains(document.activeElement)) input.focus();
            });
    }

    function miniAvatar() {
        var span = document.createElement('span');
        span.className = 'chatbot-avatar chatbot-mini-avatar';
        span.setAttribute('aria-hidden', 'true');
        var img = document.createElement('img');
        img.src = AVATAR; img.alt = ''; img.width = 64; img.height = 64;
        span.appendChild(img);
        return span;
    }

    function appendTyping(messages) {
        var row = document.createElement('div');
        row.className = 'chatbot-row chatbot-thinking';
        var bubble = document.createElement('div');
        bubble.className = 'chatbot-msg chatbot-msg-bot chatbot-typing';
        bubble.innerHTML = '<span class="chatbot-typing-label">Davo is thinking…</span><i aria-hidden="true"></i><i aria-hidden="true"></i><i aria-hidden="true"></i>';
        row.appendChild(miniAvatar());
        row.appendChild(bubble);
        messages.appendChild(row);
        messages.scrollTop = messages.scrollHeight;
        return row;
    }

    function appendMessage(messages, text, who, cards, action) {
        var el = document.createElement('div');
        el.className = 'chatbot-msg ' + (who === 'user' ? 'chatbot-msg-user' : 'chatbot-msg-bot');
        var escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        escaped = escaped.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
        el.innerHTML = escaped;

        if (cards && cards.length) {
            var list = document.createElement('div');
            list.className = 'chatbot-cards';
            cards.forEach(function (c) { list.appendChild(buildCard(c)); });
            el.appendChild(list);
        }

        if (action && action.url) {
            var go = document.createElement('a');
            go.className = 'chatbot-action';
            go.href = action.url;
            go.textContent = action.label + ' →';
            el.appendChild(go);
        }

        if (who === 'user') {
            messages.appendChild(el);
        } else {
            var row = document.createElement('div');
            row.className = 'chatbot-row';
            row.appendChild(miniAvatar());
            row.appendChild(el);
            messages.appendChild(row);
        }
        messages.scrollTop = messages.scrollHeight;
        return el;
    }

    function buildCard(c) {
        var a = document.createElement('a');
        a.className = 'chatbot-card';
        a.href = c.url;
        if (c.image) {
            var img = document.createElement('img');
            img.className = 'chatbot-card-img'; img.src = c.image; img.alt = ''; img.width = 58; img.height = 58; img.loading = 'lazy';
            a.appendChild(img);
        }
        var body = document.createElement('span');
        body.className = 'chatbot-card-body';
        function line(cls, txt) { var s = document.createElement('span'); s.className = cls; s.textContent = txt; body.appendChild(s); }
        line('chatbot-card-name', c.name);
        if (c.where) line('chatbot-card-where', c.where);
        line('chatbot-card-meta', (c.meta ? c.meta + ' · ' : '') + 'View →');
        a.appendChild(body);
        return a;
    }
})();
</script>
