<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>ZeniClaw — Guide d'installation Windows + WSL</title>
<meta name="description" content="Guide complet pour installer ZeniClaw sur Windows avec WSL (Windows Subsystem for Linux). Installation pas a pas, de WSL a ZeniClaw.">
<link rel="icon" href="/favicon.ico" type="image/x-icon">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<style>
:root {
  --bg-primary: #0a0e17;
  --bg-secondary: #111827;
  --bg-card: #1a1f2e;
  --bg-card-hover: #232940;
  --text-primary: #f1f5f9;
  --text-secondary: #94a3b8;
  --text-muted: #64748b;
  --accent-blue: #3b82f6;
  --accent-purple: #8b5cf6;
  --accent-pink: #ec4899;
  --accent-green: #10b981;
  --accent-amber: #f59e0b;
  --accent-red: #ef4444;
  --accent-cyan: #06b6d4;
  --gradient: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899);
  --gradient-subtle: linear-gradient(135deg, rgba(59,130,246,0.1), rgba(139,92,246,0.1), rgba(236,72,153,0.1));
  --border: #1e293b;
  --radius: 12px;
  --font: 'Inter', system-ui, -apple-system, sans-serif;
  --mono: 'JetBrains Mono', 'Fira Code', monospace;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }

body {
  font-family: var(--font);
  background: var(--bg-primary);
  color: var(--text-primary);
  line-height: 1.7;
  -webkit-font-smoothing: antialiased;
}

::-webkit-scrollbar { width: 8px; }
::-webkit-scrollbar-track { background: var(--bg-primary); }
::-webkit-scrollbar-thumb { background: var(--bg-card); border-radius: 4px; }

nav {
  position: fixed; top: 0; width: 100%; z-index: 100;
  background: rgba(10, 14, 23, 0.85);
  backdrop-filter: blur(20px);
  border-bottom: 1px solid var(--border);
  padding: 0 2rem;
}
.nav-inner {
  max-width: 1200px; margin: 0 auto;
  display: flex; align-items: center; justify-content: space-between; height: 64px;
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
  font-weight: 700; font-size: 1.25rem;
  text-decoration: none; color: var(--text-primary);
}
.nav-logo-icon {
  width: 32px; height: 32px; background: var(--gradient);
  border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.nav-links { display: flex; gap: 1.5rem; list-style: none; align-items: center; }
.nav-links a {
  color: var(--text-secondary); text-decoration: none;
  font-size: 0.9rem; font-weight: 500; transition: color 0.2s;
}
.nav-links a:hover { color: var(--text-primary); }
.nav-cta {
  padding: 8px 20px !important; background: var(--gradient);
  color: #fff !important; border-radius: 8px;
  font-weight: 600 !important; font-size: 0.85rem !important; transition: opacity 0.2s;
}
.nav-cta:hover { opacity: 0.9; }

.doc-container {
  max-width: 860px; margin: 0 auto;
  padding: 100px 2rem 80px;
}

.doc-header {
  text-align: center; margin-bottom: 3rem;
  padding-bottom: 2rem; border-bottom: 1px solid var(--border);
}
.doc-header h1 {
  font-size: clamp(2rem, 5vw, 3rem); font-weight: 800;
  line-height: 1.1; margin-bottom: 1rem; letter-spacing: -0.02em;
}
.gradient-text {
  background: var(--gradient);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.doc-header p {
  color: var(--text-secondary); font-size: 1.1rem; max-width: 600px; margin: 0 auto;
}

.doc-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 16px; background: var(--bg-card);
  border: 1px solid var(--border); border-radius: 50px;
  font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1.5rem;
}

.toc {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.5rem 2rem; margin-bottom: 3rem;
}
.toc h3 { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-muted); margin-bottom: 1rem; }
.toc ol { padding-left: 1.2rem; }
.toc li { margin-bottom: 0.5rem; }
.toc a { color: var(--accent-blue); text-decoration: none; font-size: 0.95rem; }
.toc a:hover { text-decoration: underline; }

.doc-section { margin-bottom: 3rem; }
.doc-section h2 {
  font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem;
  padding-bottom: 0.5rem; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 0.5rem;
}
.doc-section h3 {
  font-size: 1.15rem; font-weight: 600; margin: 1.5rem 0 0.75rem;
  color: var(--accent-blue);
}

.doc-section p { color: var(--text-secondary); margin-bottom: 1rem; }
.doc-section ul, .doc-section ol { color: var(--text-secondary); padding-left: 1.5rem; margin-bottom: 1rem; }
.doc-section li { margin-bottom: 0.4rem; }

.code-block {
  position: relative; background: #0d1117; border: 1px solid var(--border);
  border-radius: 8px; margin: 1rem 0 1.5rem; overflow: hidden;
}
.code-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 16px; background: rgba(255,255,255,0.03);
  border-bottom: 1px solid var(--border);
  font-size: 0.75rem; color: var(--text-muted); font-family: var(--mono);
}
.code-block pre {
  padding: 1rem 1.25rem; overflow-x: auto;
  font-family: var(--mono); font-size: 0.85rem; line-height: 1.6;
  color: #e6edf3;
}
.code-block .comment { color: #8b949e; }
.code-block .cmd { color: #79c0ff; }
.code-block .string { color: #a5d6ff; }
.code-block .prompt { color: var(--accent-green); }
.code-block .output { color: var(--text-muted); }

.copy-btn {
  background: transparent; border: 1px solid var(--border); border-radius: 4px;
  color: var(--text-muted); padding: 2px 8px; font-size: 0.7rem; cursor: pointer;
  font-family: var(--font); transition: all 0.2s;
}
.copy-btn:hover { color: var(--text-primary); border-color: var(--text-muted); }

.callout {
  background: var(--bg-card); border-left: 3px solid var(--accent-blue);
  border-radius: 0 8px 8px 0; padding: 1rem 1.25rem; margin: 1rem 0 1.5rem;
}
.callout.warning { border-left-color: var(--accent-amber); }
.callout.success { border-left-color: var(--accent-green); }
.callout.danger { border-left-color: var(--accent-red); }
.callout p { margin-bottom: 0; font-size: 0.9rem; }
.callout strong { color: var(--text-primary); }

.prereq-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem; margin: 1rem 0 1.5rem;
}
.prereq-item {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1rem; text-align: center;
}
.prereq-item .icon { font-size: 1.5rem; margin-bottom: 0.5rem; }
.prereq-item .label { font-weight: 600; font-size: 0.9rem; }
.prereq-item .detail { color: var(--text-muted); font-size: 0.8rem; }

.step-num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; background: var(--gradient);
  border-radius: 50%; font-size: 0.8rem; font-weight: 700; color: #fff;
  flex-shrink: 0;
}

footer {
  border-top: 1px solid var(--border); padding: 2rem;
  text-align: center; color: var(--text-muted); font-size: 0.85rem;
}
footer a { color: var(--text-secondary); text-decoration: none; }
footer a:hover { color: var(--text-primary); }

@media (max-width: 768px) {
  .nav-links { display: none; }
  .doc-container { padding: 80px 1rem 40px; }
  .prereq-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>

<body x-data="{ lang: 'fr' }">

<nav>
  <div class="nav-inner">
    <a href="/" class="nav-logo">
      <div class="nav-logo-icon">Z</div>
      ZeniClaw
    </a>
    <ul class="nav-links">
      <li><a href="/">Accueil</a></li>
      <li><a href="/#agents">Agents</a></li>
      <li><a href="/#technology">Technologie</a></li>
      <li><a href="https://github.com/zeniclaw/core" target="_blank">GitHub</a></li>
      @if(!\App\Http\Middleware\BlockAuthOnOfficialDomain::isOfficialDomain())
        @auth
          <li><a href="{{ route('dashboard') }}" class="nav-cta">Dashboard</a></li>
        @else
          <li><a href="{{ route('login') }}" class="nav-cta">Sign In</a></li>
        @endauth
      @endif
    </ul>
  </div>
</nav>

<div class="doc-container">

  <div class="doc-header">
    <div class="doc-badge">Guide d'installation</div>
    <h1>Installer ZeniClaw sur <span class="gradient-text">Windows + WSL</span></h1>
    <p>De zero a une plateforme IA fonctionnelle sur votre PC Windows, en 15 minutes.</p>
  </div>

  {{-- TABLE OF CONTENTS --}}
  <div class="toc">
    <h3>Sommaire</h3>
    <ol>
      <li><a href="#prereqs">Pre-requis</a></li>
      <li><a href="#wsl">Installer WSL</a></li>
      <li><a href="#zeniclaw">Installer ZeniClaw</a></li>
      <li><a href="#access">Acceder a ZeniClaw</a></li>
      <li><a href="#config">Configuration initiale</a></li>
      <li><a href="#update">Mettre a jour</a></li>
      <li><a href="#troubleshoot">Depannage</a></li>
    </ol>
  </div>

  {{-- PRE-REQUIS --}}
  <div class="doc-section" id="prereqs">
    <h2><span class="step-num">0</span> Pre-requis</h2>

    <div class="prereq-grid">
      <div class="prereq-item">
        <div class="icon">&#x1F4BB;</div>
        <div class="label">Windows 10/11</div>
        <div class="detail">Version 2004+ (Build 19041+)</div>
      </div>
      <div class="prereq-item">
        <div class="icon">&#x1F9E0;</div>
        <div class="label">RAM</div>
        <div class="detail">8 Go minimum, 16 Go recommande</div>
      </div>
      <div class="prereq-item">
        <div class="icon">&#x1F4BE;</div>
        <div class="label">Disque</div>
        <div class="detail">10 Go libres minimum</div>
      </div>
      <div class="prereq-item">
        <div class="icon">&#x1F310;</div>
        <div class="label">Internet</div>
        <div class="detail">Pour le telechargement initial</div>
      </div>
    </div>

    <div class="callout">
      <p><strong>Virtualisation requise :</strong> WSL necessite que la virtualisation soit activee dans le BIOS/UEFI de votre machine. Sur la plupart des PC recents, c'est deja le cas. Si l'installation de WSL echoue, verifiez les parametres <em>Intel VT-x</em> ou <em>AMD-V</em> dans votre BIOS.</p>
    </div>
  </div>

  {{-- INSTALL WSL --}}
  <div class="doc-section" id="wsl">
    <h2><span class="step-num">1</span> Installer WSL</h2>

    <p>WSL (Windows Subsystem for Linux) permet d'executer Linux directement sur Windows, sans machine virtuelle lourde. ZeniClaw tourne dans un environnement Linux via WSL.</p>

    <h3>1.1 — Ouvrir PowerShell en administrateur</h3>
    <p>Faites un clic droit sur le menu Demarrer et selectionnez <strong>"Terminal (admin)"</strong> ou <strong>"PowerShell (admin)"</strong>.</p>

    <h3>1.2 — Installer WSL et Ubuntu</h3>
    <div class="code-block">
      <div class="code-header"><span>PowerShell (Admin)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="cmd">wsl --install</span></pre>
    </div>

    <p>Cette commande installe automatiquement WSL 2 avec Ubuntu. Votre PC redemarrera peut-etre.</p>

    <div class="callout">
      <p><strong>Premiere fois ?</strong> Apres le redemarrage, Ubuntu se lancera et vous demandera de creer un nom d'utilisateur et un mot de passe. Ce sont vos identifiants Linux, pas ceux de Windows.</p>
    </div>

    <h3>1.3 — Verifier l'installation</h3>
    <p>Ouvrez un terminal Ubuntu (cherchez "Ubuntu" dans le menu Demarrer) et tapez :</p>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">wsl --version</span>
<span class="output">WSL version: 2.x.x.x
Kernel version: 5.15.x.x
...</span></pre>
    </div>

    <h3>1.4 — Mettre a jour les paquets</h3>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">sudo apt update && sudo apt upgrade -y</span></pre>
    </div>
  </div>

  {{-- INSTALL ZENICLAW --}}
  <div class="doc-section" id="zeniclaw">
    <h2><span class="step-num">2</span> Installer ZeniClaw</h2>

    <p>L'installation de ZeniClaw se fait en une seule commande. Le script installe automatiquement Podman (moteur de conteneurs), configure tout et demarre les services.</p>

    <h3>2.1 — Lancer l'installateur</h3>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">bash <(curl -fsSL https://raw.githubusercontent.com/zeniclaw/core/main/install.sh)</span></pre>
    </div>

    <p>Le script va automatiquement :</p>
    <ol>
      <li>Installer Podman, Git et les dependances necessaires</li>
      <li>Vous demander la configuration (port, cles API, proxy, etc.)</li>
      <li>Telecharger et construire les conteneurs (app, base de donnees, cache, etc.)</li>
      <li>Initialiser la base de donnees et creer les tables</li>
      <li>Demarrer tous les services</li>
    </ol>

    <h3>2.2 — Configuration interactive</h3>
    <p>Le script vous posera quelques questions :</p>

    <div class="callout">
      <p><strong>Port HTTP</strong> — Port d'acces web (defaut : <code>8080</code>). Gardez le defaut sauf si ce port est deja utilise.</p>
    </div>
    <div class="callout">
      <p><strong>Cles API LLM</strong> — Optionnel. Vous pouvez ajouter votre cle API Anthropic (Claude), OpenAI, ou autre. Vous pourrez aussi les configurer plus tard depuis l'interface.</p>
    </div>
    <div class="callout">
      <p><strong>Ollama (LLM local)</strong> — Si vous voulez utiliser des modeles IA en local (sans API externe), activez Ollama. Necessite ~8 Go de RAM supplementaires.</p>
    </div>

    <h3>2.3 — Attendre la fin de l'installation</h3>
    <p>La premiere installation prend 5 a 15 minutes selon votre connexion. Le script affiche la progression en temps reel :</p>
    <div class="code-block">
      <div class="code-header"><span>Sortie d'installation</span></div>
      <pre><span class="output">-- 1/6 -- Pre-flight Checks --</span>
<span class="comment">  Podman ................ OK</span>
<span class="comment">  Git ................... OK</span>
<span class="comment">  Ports ................. OK</span>

<span class="output">-- 4/6 -- Building & Starting Services --</span>
<span class="comment">  [1/3] Pulling db (postgres:16-alpine) ... OK</span>
<span class="comment">  [2/3] Pulling redis (redis:7-alpine) .... OK</span>
<span class="comment">  Building container images ............... OK</span>
<span class="comment">  Starting services ....................... OK</span>

<span class="output">-- 6/6 -- Installation Complete! --</span>
<span class="string">  ZeniClaw is running at http://localhost:8080</span></pre>
    </div>
  </div>

  {{-- ACCESS --}}
  <div class="doc-section" id="access">
    <h2><span class="step-num">3</span> Acceder a ZeniClaw</h2>

    <p>Une fois l'installation terminee, ouvrez votre navigateur Windows et allez sur :</p>

    <div class="code-block">
      <div class="code-header"><span>Navigateur Windows</span></div>
      <pre><span class="string">http://localhost:8080</span></pre>
    </div>

    <p>Vous arriverez sur la page de connexion. Creez votre compte administrateur en cliquant sur <strong>"Create Account"</strong>.</p>

    <div class="callout success">
      <p><strong>C'est tout !</strong> ZeniClaw est pret. Le premier compte cree aura automatiquement les droits super-administrateur.</p>
    </div>

    <h3>Acces depuis d'autres appareils (reseau local)</h3>
    <p>Pour acceder a ZeniClaw depuis un autre PC ou votre telephone sur le meme reseau :</p>
    <ol>
      <li>Trouvez l'IP de votre PC Windows : <code>ipconfig</code> dans PowerShell</li>
      <li>Accedez a <code>http://VOTRE_IP:8080</code> depuis l'autre appareil</li>
    </ol>
  </div>

  {{-- CONFIG --}}
  <div class="doc-section" id="config">
    <h2><span class="step-num">4</span> Configuration initiale</h2>

    <h3>Ajouter une cle API</h3>
    <p>Pour utiliser les agents IA, vous avez besoin d'au moins une cle API LLM :</p>
    <ol>
      <li>Allez dans <strong>Settings</strong> (icone engrenage)</li>
      <li>Section <strong>"LLM API Keys"</strong></li>
      <li>Ajoutez votre cle Anthropic (Claude) ou OpenAI</li>
    </ol>

    <h3>Utiliser Ollama (IA locale, sans API)</h3>
    <p>Si vous avez active Ollama a l'installation, telechargez un modele :</p>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">podman exec zeniclaw_ollama ollama pull llama3.2</span>
<span class="comment"># Ou un modele plus leger :</span>
<span class="prompt">$</span> <span class="cmd">podman exec zeniclaw_ollama ollama pull phi3:mini</span></pre>
    </div>
    <p>Le modele sera disponible automatiquement dans l'interface ZeniClaw.</p>
  </div>

  {{-- UPDATE --}}
  <div class="doc-section" id="update">
    <h2><span class="step-num">5</span> Mettre a jour</h2>

    <h3>Depuis l'interface web</h3>
    <p>Allez dans <strong>Admin > Update</strong> et cliquez sur <strong>"Update"</strong>. Le code sera mis a jour automatiquement.</p>

    <h3>Depuis le terminal</h3>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">cd ~/zeniclaw</span>
<span class="prompt">$</span> <span class="cmd">git pull origin main</span>
<span class="prompt">$</span> <span class="cmd">podman compose up -d --build --force-recreate app</span></pre>
    </div>
  </div>

  {{-- TROUBLESHOOT --}}
  <div class="doc-section" id="troubleshoot">
    <h2><span class="step-num">6</span> Depannage</h2>

    <h3>"wsl --install" ne fait rien</h3>
    <p>Votre version de Windows est peut-etre trop ancienne. Mettez a jour Windows via <strong>Parametres > Windows Update</strong>, puis reessayez.</p>

    <h3>"systemctl" ne fonctionne pas dans WSL</h3>
    <p>Systemd doit etre active. Executez :</p>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">echo -e "[boot]\nsystemd=true" | sudo tee /etc/wsl.conf</span>
<span class="comment"># Puis dans PowerShell (pas Ubuntu) :</span>
<span class="prompt">PS></span> <span class="cmd">wsl --shutdown</span>
<span class="comment"># Reouvrez Ubuntu</span></pre>
    </div>

    <h3>Le port 8080 est deja utilise</h3>
    <p>Modifiez le port dans le fichier <code>.env</code> :</p>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">cd ~/zeniclaw</span>
<span class="prompt">$</span> <span class="cmd">nano .env</span>
<span class="comment"># Changez APP_PORT=8080 en APP_PORT=9090 (ou autre)</span>
<span class="prompt">$</span> <span class="cmd">podman compose down && podman compose up -d</span></pre>
    </div>

    <h3>Les conteneurs ne demarrent pas</h3>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="comment"># Verifier l'etat des conteneurs :</span>
<span class="prompt">$</span> <span class="cmd">podman ps -a</span>

<span class="comment"># Voir les logs d'un conteneur :</span>
<span class="prompt">$</span> <span class="cmd">podman logs zeniclaw_app</span>

<span class="comment"># Tout relancer :</span>
<span class="prompt">$</span> <span class="cmd">cd ~/zeniclaw && podman compose down && podman compose up -d</span></pre>
    </div>

    <h3>Relancer ZeniClaw apres un redemarrage Windows</h3>
    <p>WSL demarre automatiquement, mais les conteneurs doivent etre relances :</p>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">cd ~/zeniclaw && podman compose up -d</span></pre>
    </div>

    <div class="callout">
      <p><strong>Demarrage automatique :</strong> Pour lancer ZeniClaw automatiquement au demarrage de Windows, ajoutez cette ligne a votre <code>~/.bashrc</code> :<br>
      <code>cd ~/zeniclaw && podman compose up -d 2>/dev/null</code></p>
    </div>

    <h3>Desinstaller ZeniClaw</h3>
    <div class="code-block">
      <div class="code-header"><span>Ubuntu (WSL)</span> <button class="copy-btn" onclick="copyCode(this)">Copier</button></div>
      <pre><span class="prompt">$</span> <span class="cmd">cd ~/zeniclaw && podman compose down -v</span>
<span class="prompt">$</span> <span class="cmd">cd ~ && rm -rf zeniclaw</span></pre>
    </div>
  </div>

</div>

<footer>
  <p>&copy; 2026 <a href="https://www.zenibiz.com" target="_blank">ZeniClaw by ZeniBiz</a> &mdash; Plateforme IA d'entreprise on-prem</p>
  <p style="margin-top: 0.5rem;"><a href="/">Accueil</a> &middot; <a href="https://github.com/zeniclaw/core" target="_blank">GitHub</a> &middot; <a href="/#contact">Contact</a></p>
</footer>

<script>
function copyCode(btn) {
  const pre = btn.closest('.code-block').querySelector('pre');
  const lines = pre.innerText.split('\n').filter(l => !l.startsWith('#') && l.trim() !== '');
  const cmds = lines.map(l => l.replace(/^\$\s*/, '').replace(/^PS>\s*/, '')).join('\n');
  if (navigator.clipboard) {
    navigator.clipboard.writeText(cmds);
  } else {
    const ta = document.createElement('textarea');
    ta.value = cmds;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
  btn.textContent = 'Copie !';
  setTimeout(() => btn.textContent = 'Copier', 2000);
}
</script>

</body>
</html>
