/*
 * OpsPilot website chat widget.
 * Embedded via: <script src=".../widget.js" data-widget-key="..." data-api="..." async></script>
 * Vanilla JS, no dependencies, safe to drop into any website.
 */
(function () {
    var scriptTag = document.currentScript;
    var widgetKey = scriptTag.getAttribute('data-widget-key');
    var apiUrl = scriptTag.getAttribute('data-api');

    if (!widgetKey || !apiUrl) {
        console.warn('OpsPilot chat widget: missing data-widget-key or data-api.');
        return;
    }

    var storageKey = 'opspilot_visitor_' + widgetKey;
    var state = JSON.parse(localStorage.getItem(storageKey) || '{}');
    var lastMessageId = 0;
    var pollTimer = null;

    function call(action, payload) {
        return fetch(apiUrl + '?key=' + encodeURIComponent(widgetKey) + '&action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action, website: '' }, payload || {})),
        }).then(function (res) { return res.json(); });
    }

    function save() {
        localStorage.setItem(storageKey, JSON.stringify(state));
    }

    // ---- Build the UI ----

    var style = document.createElement('style');
    style.textContent =
        '.oppi-bubble{position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;' +
        'background:var(--oppi-color,#2ecc71);box-shadow:0 4px 16px rgba(0,0,0,0.25);cursor:pointer;' +
        'display:flex;align-items:center;justify-content:center;z-index:999999;border:none;}' +
        '.oppi-bubble svg{width:26px;height:26px;fill:#fff;}' +
        '.oppi-panel{position:fixed;bottom:88px;right:20px;width:320px;max-width:90vw;height:440px;' +
        'max-height:70vh;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.3);' +
        'display:none;flex-direction:column;overflow:hidden;z-index:999999;font-family:system-ui,sans-serif;}' +
        '.oppi-panel.open{display:flex;}' +
        '.oppi-header{background:var(--oppi-color,#2ecc71);color:#fff;padding:14px;font-weight:600;}' +
        '.oppi-messages{flex:1;overflow-y:auto;padding:12px;background:#f7f7f8;}' +
        '.oppi-msg{max-width:80%;padding:8px 12px;border-radius:10px;margin-bottom:8px;font-size:0.9rem;line-height:1.4;}' +
        '.oppi-msg.visitor{background:var(--oppi-color,#2ecc71);color:#fff;margin-left:auto;}' +
        '.oppi-msg.staff{background:#e9e9eb;color:#111;}' +
        '.oppi-input-row{display:flex;border-top:1px solid #eee;}' +
        '.oppi-input-row input{flex:1;border:none;padding:12px;font-size:0.9rem;outline:none;}' +
        '.oppi-input-row button{border:none;background:var(--oppi-color,#2ecc71);color:#fff;padding:0 16px;cursor:pointer;}';
    document.head.appendChild(style);

    var bubble = document.createElement('button');
    bubble.className = 'oppi-bubble';
    bubble.setAttribute('aria-label', 'Open chat');
    bubble.innerHTML = '<svg viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';
    document.body.appendChild(bubble);

    var panel = document.createElement('div');
    panel.className = 'oppi-panel';
    panel.innerHTML =
        '<div class="oppi-header">Chat with us</div>' +
        '<div class="oppi-messages"></div>' +
        '<div class="oppi-input-row">' +
        '<input type="text" placeholder="Type a message..." maxlength="2000">' +
        '<button type="button">Send</button>' +
        '</div>';
    document.body.appendChild(panel);

    var messagesEl = panel.querySelector('.oppi-messages');
    var inputEl = panel.querySelector('input');
    var sendBtn = panel.querySelector('button');

    function addMessage(text, who) {
        var el = document.createElement('div');
        el.className = 'oppi-msg ' + who;
        el.textContent = text;
        messagesEl.appendChild(el);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(function () {
            if (!state.conversationId) return;
            call('poll', {
                visitor_token: state.visitorToken,
                conversation_id: state.conversationId,
                after_id: lastMessageId,
            }).then(function (data) {
                (data.messages || []).forEach(function (m) {
                    addMessage(m.body, 'staff');
                    lastMessageId = Math.max(lastMessageId, m.id);
                });
            });
        }, 4000);
    }

    function ensureSession() {
        if (state.visitorToken) {
            return Promise.resolve();
        }

        return call('start', {}).then(function (data) {
            state.visitorToken = data.visitor_token;
            save();
            if (data.welcome_message) {
                addMessage(data.welcome_message, 'staff');
            }
        });
    }

    function send() {
        var text = inputEl.value.trim();
        if (!text) return;

        addMessage(text, 'visitor');
        inputEl.value = '';

        ensureSession().then(function () {
            return call('send', { visitor_token: state.visitorToken, message: text });
        }).then(function (data) {
            if (data.conversation_id) {
                state.conversationId = data.conversation_id;
                save();
                startPolling();
            }
            if (data.error) {
                addMessage('(' + data.error + ')', 'staff');
            }
        });
    }

    bubble.addEventListener('click', function () {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            ensureSession();
            startPolling();
        }
    });

    sendBtn.addEventListener('click', send);
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') send();
    });
})();
