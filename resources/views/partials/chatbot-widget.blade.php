<div id="chatbot-widget" class="chatbot-widget">
    <button type="button" id="chatbot-toggle" class="chatbot-toggle" aria-label="Open chat assistant">
        <x-icon name="chat" />
    </button>

    <div id="chatbot-panel" class="chatbot-panel" hidden>
        <div class="chatbot-panel-head">
            <div>
                <strong>ExploreDVO Assistant</strong>
                <div class="sub" style="font-size:.72rem;">Ask about destinations, stays, food &amp; more</div>
            </div>
            <button type="button" id="chatbot-close" class="chatbot-close" aria-label="Close chat">&times;</button>
        </div>
        <div id="chatbot-messages" class="chatbot-messages">
            <div class="chatbot-msg chatbot-msg-bot">Hi! I'm the ExploreDVO assistant. Ask me about destinations, accommodations, restaurants, tour packages, souvenir centers, or tour operators &mdash; or ask if a place is DOT-accredited.</div>
        </div>
        <form id="chatbot-form" class="chatbot-form">
            <input type="text" id="chatbot-input" placeholder="Type a message&hellip;" maxlength="500" autocomplete="off">
            <button type="submit" class="btn btn-primary" style="padding:8px 14px;">Send</button>
        </form>
    </div>
</div>

<style>
    .chatbot-widget { position: fixed; right: 20px; bottom: 20px; z-index: 200; }
    .chatbot-toggle {
        width: 54px; height: 54px; border-radius: 50%; border: none; cursor: pointer;
        background: var(--primary); color: var(--white); display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 18px rgba(0,0,0,.18); transition: transform .15s ease;
    }
    .chatbot-toggle:hover { transform: scale(1.06); background: var(--primary-dark); }
    .chatbot-toggle svg { width: 24px; height: 24px; }

    .chatbot-panel {
        position: absolute; right: 0; bottom: 66px; width: 340px; max-width: calc(100vw - 40px);
        background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-lg);
        box-shadow: 0 12px 32px rgba(0,0,0,.18); display: flex; flex-direction: column; overflow: hidden;
        max-height: 480px;
    }
    .chatbot-panel-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 16px; background: var(--primary); color: var(--white);
    }
    .chatbot-panel-head .sub { color: rgba(255,255,255,.8); }
    .chatbot-close {
        background: none; border: none; color: var(--white); font-size: 1.5rem; line-height: 1; cursor: pointer;
        width: 32px; height: 32px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
        border-radius: 50%; margin: -4px -4px -4px 8px;
    }
    .chatbot-close:hover, .chatbot-close:active { background: rgba(255,255,255,.18); }

    .chatbot-messages { flex: 1; overflow-y: auto; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; min-height: 220px; }
    .chatbot-msg { font-size: .84rem; line-height: 1.45; padding: 9px 12px; border-radius: var(--radius-md); max-width: 88%; white-space: pre-line; }
    .chatbot-msg-bot { background: var(--bg); color: var(--ink); align-self: flex-start; border-bottom-left-radius: 2px; }
    .chatbot-msg-user { background: var(--primary-light); color: var(--primary-dark); align-self: flex-end; border-bottom-right-radius: 2px; }
    .chatbot-msg a { color: var(--primary-dark); }

    .chatbot-form { display: flex; gap: 8px; padding: 12px; border-top: 1px solid var(--border); }
    .chatbot-form input {
        flex: 1; border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 8px 10px; font-size: .84rem;
    }
    .chatbot-form input:focus { outline: none; border-color: var(--primary); }

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
    }
</style>

<script>
(function () {
    // Event delegation on document/window instead of cached element references,
    // so open/close can never break due to element-lookup timing or stale refs
    // — every handler re-queries the DOM fresh at the moment of the event.

    function getPanel() { return document.getElementById('chatbot-panel'); }
    function getWidget() { return document.getElementById('chatbot-widget'); }

    function setOpen(open) {
        var panel = getPanel();
        if (!panel) return;
        panel.hidden = !open;
        panel.style.display = open ? 'flex' : 'none';
        if (open) {
            var input = document.getElementById('chatbot-input');
            if (input) input.focus();
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
        if (!panel.hidden && !widget.contains(e.target)) {
            setOpen(false);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        var panel = getPanel();
        if (panel && e.key === 'Escape' && !panel.hidden) setOpen(false);
    });

    document.addEventListener('submit', function (e) {
        if (e.target.id !== 'chatbot-form') return;
        e.preventDefault();

        var form = e.target;
        var input = document.getElementById('chatbot-input');
        var messages = document.getElementById('chatbot-messages');
        var csrfToken = document.querySelector('meta[name="csrf-token"]');
        csrfToken = csrfToken ? csrfToken.content : '';

        var text = input.value.trim();
        if (!text) return;

        appendMessage(messages, text, 'user');
        input.value = '';
        input.disabled = true;

        fetch('{{ route('chatbot.respond') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ message: text }),
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                appendMessage(messages, data.response || 'Sorry, something went wrong.', 'bot');
            })
            .catch(function () {
                appendMessage(messages, "Sorry, I couldn't reach the assistant right now. Please try again.", 'bot');
            })
            .finally(function () {
                input.disabled = false;
                input.focus();
            });
    });

    function appendMessage(messages, text, who) {
        var el = document.createElement('div');
        el.className = 'chatbot-msg ' + (who === 'user' ? 'chatbot-msg-user' : 'chatbot-msg-bot');
        var escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        escaped = escaped.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
        el.innerHTML = escaped;
        messages.appendChild(el);
        messages.scrollTop = messages.scrollHeight;
    }
})();
</script>
