<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $config['title'] }}</title>
<meta name="robots" content="noindex, nofollow">
<style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --primary: {{ $config['primary'] }};
        --primary-dark: color-mix(in srgb, {{ $config['primary'] }} 85%, black);
        --primary-light: color-mix(in srgb, {{ $config['primary'] }} 15%, white);
    }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        background: #f3f4f6;
        height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .chat-header {
        background: var(--primary);
        color: white;
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        flex-shrink: 0;
    }
    .chat-header .logo {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255,255,255,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        font-weight: 700;
        flex-shrink: 0;
    }
    .chat-header .logo img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
    }
    .chat-header .info h1 { font-size: 1.1rem; font-weight: 600; }
    .chat-header .info p { font-size: 0.8rem; opacity: 0.85; }
    .chat-messages {
        flex: 1;
        overflow-y: auto;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .msg {
        max-width: 75%;
        padding: 0.75rem 1rem;
        border-radius: 1.25rem;
        font-size: 0.9rem;
        line-height: 1.5;
        word-wrap: break-word;
        white-space: pre-wrap;
        animation: fadeIn 0.2s ease;
    }
    .msg.bot {
        align-self: flex-start;
        background: white;
        color: #1f2937;
        border-bottom-left-radius: 0.25rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .msg.user {
        align-self: flex-end;
        background: var(--primary);
        color: white;
        border-bottom-right-radius: 0.25rem;
    }
    .msg.typing {
        align-self: flex-start;
        background: white;
        color: #9ca3af;
        border-bottom-left-radius: 0.25rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .msg.typing .dots span {
        display: inline-block;
        width: 6px; height: 6px;
        margin: 0 2px;
        background: #9ca3af;
        border-radius: 50%;
        animation: bounce 1.4s infinite ease-in-out both;
    }
    .msg.typing .dots span:nth-child(1) { animation-delay: -0.32s; }
    .msg.typing .dots span:nth-child(2) { animation-delay: -0.16s; }
    .chat-input {
        padding: 1rem 1.5rem;
        background: white;
        border-top: 1px solid #e5e7eb;
        display: flex;
        gap: 0.75rem;
        flex-shrink: 0;
    }
    .chat-input input {
        flex: 1;
        border: 1px solid #d1d5db;
        border-radius: 1.5rem;
        padding: 0.75rem 1.25rem;
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }
    .chat-input input:focus { border-color: var(--primary); }
    .chat-input button {
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 50%;
        width: 44px; height: 44px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
        flex-shrink: 0;
    }
    .chat-input button:hover { background: var(--primary-dark); }
    .chat-input button:disabled { opacity: 0.5; cursor: not-allowed; }
    .powered-by {
        text-align: center;
        padding: 0.5rem;
        font-size: 0.7rem;
        color: #9ca3af;
        background: white;
        flex-shrink: 0;
    }
    .powered-by a { color: var(--primary); text-decoration: none; }
    @@keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
    @@keyframes bounce {
        0%, 80%, 100% { transform: scale(0); }
        40% { transform: scale(1); }
    }
</style>
</head>
<body>

<div class="chat-header">
    <div class="logo">
        @if($config['logo_url'])
            <img src="{{ $config['logo_url'] }}" alt="Logo">
        @else
            {{ mb_substr($config['title'], 0, 1) }}
        @endif
    </div>
    <div class="info">
        <h1>{{ $config['title'] }}</h1>
        <p>{{ $config['subtitle'] }}</p>
    </div>
</div>

<div class="chat-messages" id="messages">
    <div class="msg bot">{{ $config['welcome'] }}</div>
</div>

<div class="chat-input">
    <input type="text" id="input" placeholder="{{ $config['placeholder'] }}" autocomplete="off">
    <button id="send" title="Envoyer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
    </button>
</div>

<div class="powered-by">Propulse par <a href="https://www.zeniclaw.io" target="_blank">ZeniClaw</a></div>

<script>
(function() {
    const messagesEl = document.getElementById('messages');
    const inputEl    = document.getElementById('input');
    const sendBtn    = document.getElementById('send');
    const API_KEY    = '{{ config("services.public_chat.api_key") ?? "" }}';

    function addMsg(text, cls) {
        const div = document.createElement('div');
        div.className = 'msg ' + cls;
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }

    function showTyping() {
        const div = document.createElement('div');
        div.className = 'msg typing';
        div.id = 'typing';
        div.innerHTML = '<div class="dots"><span></span><span></span><span></span></div>';
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function hideTyping() {
        const el = document.getElementById('typing');
        if (el) el.remove();
    }

    async function send() {
        const text = inputEl.value.trim();
        if (!text) return;

        addMsg(text, 'user');
        inputEl.value = '';
        sendBtn.disabled = true;
        showTyping();

        try {
            const res = await fetch('/api/public-chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Api-Key': API_KEY,
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
                body: JSON.stringify({ message: text }),
            });
            const data = await res.json();
            hideTyping();
            addMsg(data.reply || data.error || 'Erreur', 'bot');
        } catch (e) {
            hideTyping();
            addMsg('Erreur de connexion. Veuillez reessayer.', 'bot');
        }
        sendBtn.disabled = false;
        inputEl.focus();
    }

    sendBtn.addEventListener('click', send);
    inputEl.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
    inputEl.focus();
})();
</script>
</body>
</html>
