<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

{{-- SEO --}}
<title>ZeniClaw — Plateforme IA d'Entreprise On-Prem | 36+ Agents IA | Souverainete des Donnees</title>
<meta name="description" content="ZeniClaw est la plateforme IA d'entreprise deployable sur vos serveurs. 36+ agents IA pre-construits, agents personnalises RAG, LLMs on-prem via Ollama. Souverainete totale des donnees.">
<meta name="keywords" content="zeniclaw, ia entreprise, on-prem ai, agents ia, self-hosted, rag, ollama, llm local, souverainete donnees, claude, gpt-4, automatisation entreprise">
<meta name="author" content="ZeniBiz">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{{ url('/') }}">

{{-- Open Graph --}}
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url('/') }}">
<meta property="og:title" content="ZeniClaw — Enterprise AI Platform, On Your Servers">
<meta property="og:description" content="Plateforme IA d'entreprise on-prem avec 36+ agents IA, agents personnalises RAG, LLMs locaux via Ollama. Souverainete totale des donnees.">
<meta property="og:image" content="{{ url('/og-image.php') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta property="og:site_name" content="ZeniClaw">
<meta property="og:locale" content="fr_FR">

{{-- Twitter Card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="ZeniClaw — Enterprise AI Platform, On Your Servers">
<meta name="twitter:description" content="Plateforme IA d'entreprise on-prem. 36+ agents IA, agents personnalises RAG, LLMs locaux. Souverainete des donnees.">
<meta name="twitter:image" content="{{ url('/og-image.php') }}">

{{-- Favicon --}}
<link rel="icon" href="/favicon.ico" type="image/x-icon">

{{-- Structured Data (JSON-LD) --}}
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "SoftwareApplication",
  "name": "ZeniClaw",
  "description": "Plateforme IA d'entreprise deployable on-prem avec 36+ agents IA specialises, agents personnalises RAG, et LLMs locaux via Ollama. Souverainete totale des donnees.",
  "url": "{{ url('/') }}",
  "applicationCategory": "BusinessApplication",
  "operatingSystem": "Linux",
  "offers": {
    "@@type": "Offer",
    "price": "0",
    "priceCurrency": "EUR"
  },
  "author": {
    "@@type": "Organization",
    "name": "ZeniBiz",
    "url": "https://www.zenibiz.com"
  },
  "screenshot": "{{ url('/og-image.php') }}",
  "featureList": "36+ AI Agents, On-Prem Deployment, Custom RAG Agents, Local LLMs via Ollama, Data Sovereignty, Multi-Channel (WhatsApp, Web Chat, API), RBAC, Audit Logs, AES-256 Encryption, Enterprise Security"
}
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
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
  line-height: 1.6;
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
.lang-toggle {
  display: inline-flex; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; margin-left: 0.5rem;
}
.lang-btn {
  padding: 4px 10px; font-size: 0.75rem; font-weight: 600; cursor: pointer;
  background: transparent; color: var(--text-muted); border: none; font-family: var(--font); transition: all 0.2s;
}
.lang-btn.active { background: var(--accent-blue); color: #fff; }

.hero {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  text-align: center; padding: 120px 2rem 80px; position: relative; overflow: hidden;
}
.hero::before {
  content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
  background: radial-gradient(ellipse at 30% 20%, rgba(59,130,246,0.08) 0%, transparent 50%),
              radial-gradient(ellipse at 70% 60%, rgba(139,92,246,0.06) 0%, transparent 50%),
              radial-gradient(ellipse at 50% 80%, rgba(236,72,153,0.04) 0%, transparent 50%);
  animation: drift 20s ease-in-out infinite;
}
@keyframes drift { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-2%, 2%); } }

.hero-content { position: relative; z-index: 1; max-width: 800px; }

.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 6px 16px; background: var(--bg-card);
  border: 1px solid var(--border); border-radius: 50px;
  font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 2rem;
}
.hero-badge .dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--accent-green); animation: pulse 2s infinite;
}
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

.hero h1 {
  font-size: clamp(2.5rem, 6vw, 4rem); font-weight: 800;
  line-height: 1.1; margin-bottom: 1.5rem; letter-spacing: -0.02em;
}
.gradient-text {
  background: var(--gradient);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.hero p {
  font-size: clamp(1rem, 2vw, 1.25rem); color: var(--text-secondary);
  max-width: 600px; margin: 0 auto 2.5rem; line-height: 1.7;
}
.hero-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }

.btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 14px 28px; border-radius: 10px; font-weight: 600;
  font-size: 0.95rem; text-decoration: none; transition: all 0.2s;
  cursor: pointer; border: none; font-family: var(--font);
}
.btn-primary { background: var(--gradient); color: #fff; }
.btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
.btn-secondary { background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border); }
.btn-secondary:hover { background: var(--bg-card-hover); border-color: var(--accent-blue); }
.btn-outline { background: transparent; color: var(--text-primary); border: 1px solid var(--border); }
.btn-outline:hover { border-color: var(--accent-purple); background: rgba(139,92,246,0.05); }

.hero-terminal {
  margin-top: 3rem; background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.5rem; text-align: left;
  max-width: 560px; margin-left: auto; margin-right: auto;
}
.terminal-bar { display: flex; gap: 6px; margin-bottom: 1rem; }
.terminal-dot { width: 10px; height: 10px; border-radius: 50%; }
.terminal-dot.r { background: #ef4444; } .terminal-dot.y { background: #f59e0b; } .terminal-dot.g { background: #10b981; }
.terminal-line { font-family: var(--mono); font-size: 0.85rem; line-height: 1.8; color: var(--text-secondary); }
.terminal-line .prompt { color: var(--accent-green); }
.terminal-line .cmd { color: var(--text-primary); }
.terminal-line .comment { color: var(--text-muted); }
.terminal-line .url { color: var(--accent-blue); }

section { padding: 100px 2rem; }
.section-inner { max-width: 1200px; margin: 0 auto; }
.section-label {
  display: inline-block; font-family: var(--mono); font-size: 0.8rem;
  color: var(--accent-blue); background: rgba(59,130,246,0.1);
  padding: 4px 12px; border-radius: 4px; margin-bottom: 1rem;
  letter-spacing: 0.05em; text-transform: uppercase;
}
.section-title {
  font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 700;
  margin-bottom: 1rem; letter-spacing: -0.01em;
}
.section-desc { font-size: 1.05rem; color: var(--text-secondary); max-width: 600px; margin-bottom: 3rem; }

.features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; }
.feature-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 2rem; transition: all 0.3s;
  position: relative; overflow: hidden;
}
.feature-card:hover { border-color: rgba(139,92,246,0.3); transform: translateY(-2px); background: var(--bg-card-hover); }
.feature-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: var(--gradient); opacity: 0; transition: opacity 0.3s;
}
.feature-card:hover::before { opacity: 1; }
.feature-icon {
  width: 48px; height: 48px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.4rem; margin-bottom: 1.2rem;
}
.feature-icon.blue   { background: rgba(59,130,246,0.15); }
.feature-icon.purple { background: rgba(139,92,246,0.15); }
.feature-icon.pink   { background: rgba(236,72,153,0.15); }
.feature-icon.green  { background: rgba(16,185,129,0.15); }
.feature-icon.amber  { background: rgba(245,158,11,0.15); }
.feature-icon.red    { background: rgba(239,68,68,0.15); }
.feature-icon.cyan   { background: rgba(6,182,212,0.15); }
.feature-card h3 { font-size: 1.1rem; font-weight: 600; margin-bottom: 0.5rem; }
.feature-card p { font-size: 0.9rem; color: var(--text-secondary); line-height: 1.6; }

.agents-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
.agent-chip {
  display: flex; align-items: center; gap: 12px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 10px; padding: 14px 18px; transition: all 0.2s;
}
.agent-chip:hover { border-color: rgba(59,130,246,0.3); background: var(--bg-card-hover); }
.agent-emoji { font-size: 1.5rem; }
.agent-info h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 2px; }
.agent-info p { font-size: 0.75rem; color: var(--text-muted); }

.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 4rem; }
.stat-box { text-align: center; padding: 2rem 1rem; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); }
.stat-number {
  font-size: 2.5rem; font-weight: 800;
  background: var(--gradient); -webkit-background-clip: text;
  -webkit-text-fill-color: transparent; background-clip: text;
  line-height: 1; margin-bottom: 0.5rem;
}
.stat-label { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; }

.use-cases-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
.use-case-card {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 1.75rem; transition: all 0.3s;
}
.use-case-card:hover { border-color: rgba(59,130,246,0.3); transform: translateY(-2px); }
.use-case-card h3 { font-size: 1rem; font-weight: 600; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px; }
.use-case-card ul { list-style: none; }
.use-case-card li { font-size: 0.85rem; color: var(--text-secondary); padding: 4px 0; padding-left: 18px; position: relative; }
.use-case-card li::before { content: ''; position: absolute; left: 0; top: 12px; width: 6px; height: 6px; border-radius: 50%; background: var(--accent-blue); }

.arch-diagram {
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 2.5rem;
  font-family: var(--mono); font-size: 0.8rem; line-height: 1.6;
  overflow-x: auto; color: var(--text-secondary);
}
.arch-diagram .hl-blue { color: var(--accent-blue); }
.arch-diagram .hl-purple { color: var(--accent-purple); }
.arch-diagram .hl-green { color: var(--accent-green); }
.arch-diagram .hl-pink { color: var(--accent-pink); }
.arch-diagram .hl-amber { color: var(--accent-amber); }
.arch-diagram .hl-cyan { color: var(--accent-cyan); }

.tech-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
.tech-item {
  display: flex; align-items: center; gap: 12px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px; transition: all 0.2s;
}
.tech-item:hover { border-color: rgba(59,130,246,0.3); }
.tech-icon { font-size: 1.5rem; width: 40px; text-align: center; }
.tech-item span { font-size: 0.9rem; font-weight: 500; }

.install-steps { display: grid; gap: 2rem; counter-reset: step; }
.install-step { display: grid; grid-template-columns: 48px 1fr; gap: 1.5rem; align-items: start; }
.step-number {
  width: 48px; height: 48px; border-radius: 50%; background: var(--gradient);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 1.1rem; flex-shrink: 0;
}
.step-content h3 { font-size: 1.15rem; font-weight: 600; margin-bottom: 0.75rem; }
.step-content p { color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1rem; }
.code-block {
  background: var(--bg-primary); border: 1px solid var(--border);
  border-radius: 8px; padding: 1rem 1.25rem;
  font-family: var(--mono); font-size: 0.85rem; color: var(--accent-green);
  overflow-x: auto; position: relative;
}
.code-block .comment { color: var(--text-muted); }
.code-block-copy {
  position: absolute; top: 8px; right: 8px;
  background: var(--bg-card); border: 1px solid var(--border);
  color: var(--text-muted); border-radius: 6px; padding: 4px 10px;
  font-size: 0.7rem; cursor: pointer; font-family: var(--font); transition: all 0.2s;
}
.code-block-copy:hover { color: var(--text-primary); border-color: var(--accent-blue); }

.security-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.25rem; }
.security-item {
  display: flex; align-items: start; gap: 14px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 10px; padding: 1.25rem; transition: all 0.2s;
}
.security-item:hover { border-color: rgba(16,185,129,0.3); }
.security-icon { font-size: 1.3rem; flex-shrink: 0; margin-top: 2px; }
.security-item h4 { font-size: 0.9rem; font-weight: 600; margin-bottom: 4px; }
.security-item p { font-size: 0.8rem; color: var(--text-secondary); }

footer {
  border-top: 1px solid var(--border); padding: 3rem 2rem; text-align: center;
}
.footer-inner { max-width: 1200px; margin: 0 auto; }
.footer-links { display: flex; justify-content: center; gap: 2rem; margin-bottom: 1.5rem; list-style: none; flex-wrap: wrap; }
.footer-links a { color: var(--text-muted); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
.footer-links a:hover { color: var(--text-primary); }
.footer-copy { color: var(--text-muted); font-size: 0.8rem; }

@media (max-width: 768px) {
  .nav-links { display: none; }
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .install-step { grid-template-columns: 40px 1fr; gap: 1rem; }
  .step-number { width: 40px; height: 40px; font-size: 0.95rem; }
  section { padding: 60px 1.25rem; }
  #contact .section-inner > div[style*="grid-template-columns: 1fr 1fr"] { grid-template-columns: 1fr !important; }
}

@keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.fade-up { opacity: 0; animation: fadeUp 0.6s ease-out forwards; }
.fade-up.d1 { animation-delay: 0.1s; } .fade-up.d2 { animation-delay: 0.2s; }
.fade-up.d3 { animation-delay: 0.3s; } .fade-up.d4 { animation-delay: 0.4s; }
</style>
</head>
<body x-data="{ lang: navigator.language.startsWith('fr') ? 'fr' : 'en' }">

{{-- ========== NAV ========== --}}
<nav>
  <div class="nav-inner">
    <a href="/" class="nav-logo">
      <div class="nav-logo-icon">Z</div>
      ZeniClaw
    </a>
    <ul class="nav-links">
      <li><a href="#solutions" x-show="lang==='fr'">Solutions</a><a href="#solutions" x-show="lang==='en'">Solutions</a></li>
      <li><a href="#agents" x-show="lang==='fr'">Agents</a><a href="#agents" x-show="lang==='en'">Agents</a></li>
      <li><a href="#technology" x-show="lang==='fr'">Technologie</a><a href="#technology" x-show="lang==='en'">Technology</a></li>
      <li><a href="#contact" x-show="lang==='fr'">Contact</a><a href="#contact" x-show="lang==='en'">Contact</a></li>
      <li>
        <div class="lang-toggle">
          <button class="lang-btn" :class="{ 'active': lang==='fr' }" @click="lang='fr'">FR</button>
          <button class="lang-btn" :class="{ 'active': lang==='en' }" @click="lang='en'">EN</button>
        </div>
      </li>
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

{{-- ========== HERO ========== --}}
<section class="hero">
  <div class="hero-content">
    <div class="hero-badge fade-up">
      <span class="dot"></span>
      <span x-show="lang==='fr'">On-Prem &middot; 36+ Agents IA &middot; Souverainete des donnees</span>
      <span x-show="lang==='en'">On-Prem &middot; 36+ AI Agents &middot; Data Sovereignty</span>
    </div>
    <h1 class="fade-up d1">
      <span x-show="lang==='fr'">L'IA d'entreprise,<br><span class="gradient-text">sur vos serveurs</span></span>
      <span x-show="lang==='en'">Enterprise AI,<br><span class="gradient-text">on your servers</span></span>
    </h1>
    <p class="fade-up d2">
      <span x-show="lang==='fr'">Deployez des agents IA pre-construits et personnalises directement sur votre infrastructure. LLMs locaux via Ollama, zero cout API, souverainete totale des donnees.</span>
      <span x-show="lang==='en'">Deploy pre-built and custom AI agents directly on your infrastructure. Local LLMs via Ollama, zero API costs, full data sovereignty.</span>
    </p>
    <div class="hero-actions fade-up d3">
      @if(\App\Http\Middleware\BlockAuthOnOfficialDomain::isOfficialDomain())
        <a href="#contact" class="btn btn-primary">
          <span x-show="lang==='fr'">Demander une demo</span>
          <span x-show="lang==='en'">Request a Demo</span>
        </a>
      @else
        @auth
          <a href="{{ route('dashboard') }}" class="btn btn-primary">
            <span x-show="lang==='fr'">Tableau de bord</span>
            <span x-show="lang==='en'">Go to Dashboard</span>
          </a>
        @else
          <a href="#contact" class="btn btn-primary">
            <span x-show="lang==='fr'">Demander une demo</span>
            <span x-show="lang==='en'">Request a Demo</span>
          </a>
          @if (Route::has('register'))
            <a href="{{ route('register') }}" class="btn btn-secondary">
              <span x-show="lang==='fr'">Creer un compte</span>
              <span x-show="lang==='en'">Create Account</span>
            </a>
          @endif
        @endauth
      @endif
      <a href="#agents" class="btn btn-secondary">
        <span x-show="lang==='fr'">Explorer les agents</span>
        <span x-show="lang==='en'">Explore Agents</span>
      </a>
      <a href="#deploy" class="btn btn-outline">&lt;/&gt;
        <span x-show="lang==='fr'">Guide d'installation</span>
        <span x-show="lang==='en'">Install Guide</span>
      </a>
    </div>
    <div class="hero-terminal fade-up d4">
      <div class="terminal-bar">
        <div class="terminal-dot r"></div>
        <div class="terminal-dot y"></div>
        <div class="terminal-dot g"></div>
      </div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">git clone https://github.com/zeniclaw/core.git</span></div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">cd zeniclaw && bash install.sh</span></div>
      <div class="terminal-line"><span class="comment"># 5 containers: app, db, redis, gateway, ollama</span></div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">open</span> <span class="url">http://localhost:8080</span></div>
    </div>
  </div>
</section>

{{-- ========== KEY METRICS ========== --}}
<section style="padding-top: 0;">
  <div class="section-inner">
    <div class="stats-row">
      <div class="stat-box fade-up">
        <div class="stat-number">36+</div>
        <div class="stat-label" x-show="lang==='fr'">Agents IA</div>
        <div class="stat-label" x-show="lang==='en'">AI Agents</div>
      </div>
      <div class="stat-box fade-up d1">
        <div class="stat-number">100%</div>
        <div class="stat-label">On-Prem</div>
      </div>
      <div class="stat-box fade-up d2">
        <div class="stat-number">13+</div>
        <div class="stat-label" x-show="lang==='fr'">Modeles LLM</div>
        <div class="stat-label" x-show="lang==='en'">LLM Models</div>
      </div>
      <div class="stat-box fade-up d3">
        <div class="stat-number">5 min</div>
        <div class="stat-label" x-show="lang==='fr'">Deploiement</div>
        <div class="stat-label" x-show="lang==='en'">Deploy Time</div>
      </div>
    </div>
  </div>
</section>

{{-- ========== SOLUTIONS ========== --}}
<section id="solutions">
  <div class="section-inner">
    <span class="section-label">// solutions</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Trois piliers pour <span class="gradient-text">votre IA</span></span>
      <span x-show="lang==='en'">Three pillars for <span class="gradient-text">your AI</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Une plateforme complete qui s'adapte a vos besoins : agents pre-construits, agents personnalises, et LLMs locaux.</span>
      <span x-show="lang==='en'">A complete platform that adapts to your needs: pre-built agents, custom agents, and local LLMs.</span>
    </p>
    <div class="features-grid">
      <div class="feature-card" style="border-color: rgba(59,130,246,0.4); background: linear-gradient(135deg, rgba(59,130,246,0.08), var(--bg-card));">
        <div class="feature-icon blue">&#x1F916;</div>
        <h3 x-show="lang==='fr'">Agents pre-construits</h3>
        <h3 x-show="lang==='en'">Pre-built Agents</h3>
        <p x-show="lang==='fr'">36+ agents IA specialises prets a l'emploi : revue de code, gestion de projet, finance, reunions, support RH, analyse de documents. Deployes en un clic, operationnels immediatement.</p>
        <p x-show="lang==='en'">36+ specialized AI agents ready to use: code review, project management, finance, meetings, HR support, document analysis. Deployed in one click, operational immediately.</p>
      </div>
      <div class="feature-card" style="border-color: rgba(139,92,246,0.4); background: linear-gradient(135deg, rgba(139,92,246,0.08), var(--bg-card));">
        <div class="feature-icon purple">&#x1F9E0;</div>
        <h3 x-show="lang==='fr'">Agents personnalises (RAG)</h3>
        <h3 x-show="lang==='en'">Custom Agents (RAG)</h3>
        <p x-show="lang==='fr'">Creez vos propres agents entraines sur vos documents internes. Retrieval-Augmented Generation pour des reponses precises basees sur vos donnees d'entreprise. Base de connaissances privee.</p>
        <p x-show="lang==='en'">Create your own agents trained on your internal documents. Retrieval-Augmented Generation for precise answers based on your company data. Private knowledge base.</p>
      </div>
      <div class="feature-card" style="border-color: rgba(6,182,212,0.4); background: linear-gradient(135deg, rgba(6,182,212,0.08), var(--bg-card));">
        <div class="feature-icon cyan">&#x1F5A5;</div>
        <h3 x-show="lang==='fr'">LLMs On-Prem (Ollama)</h3>
        <h3 x-show="lang==='en'">On-Prem LLMs (Ollama)</h3>
        <p x-show="lang==='fr'">Executez Qwen, Mistral, Llama localement via Ollama. Zero cout API, aucune donnee ne quitte vos serveurs. Mode hybride disponible : Claude et GPT-4 quand necessaire.</p>
        <p x-show="lang==='en'">Run Qwen, Mistral, Llama locally via Ollama. Zero API costs, no data leaves your servers. Hybrid mode available: Claude and GPT-4 when needed.</p>
      </div>
    </div>
  </div>
</section>

{{-- ========== USE CASES ========== --}}
<section style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// use cases</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">L'IA dans chaque <span class="gradient-text">departement</span></span>
      <span x-show="lang==='en'">AI across every <span class="gradient-text">department</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Des agents IA specialises pour chaque equipe de votre organisation.</span>
      <span x-show="lang==='en'">Specialized AI agents for every team in your organization.</span>
    </p>
    <div class="use-cases-grid">
      <div class="use-case-card">
        <h3>&#x1F465; <span x-show="lang==='fr'">Ressources Humaines</span><span x-show="lang==='en'">Human Resources</span></h3>
        <ul>
          <li x-show="lang==='fr'">Assistant d'onboarding pour les nouveaux employes</li>
          <li x-show="lang==='en'">Onboarding assistant for new employees</li>
          <li x-show="lang==='fr'">Agent FAQ interne (politiques, avantages, conges)</li>
          <li x-show="lang==='en'">Internal FAQ agent (policies, benefits, leave)</li>
          <li x-show="lang==='fr'">Suivi du bien-etre et de l'engagement</li>
          <li x-show="lang==='en'">Wellness and engagement tracking</li>
        </ul>
      </div>
      <div class="use-case-card">
        <h3>&#x1F4B0; Finance</h3>
        <ul>
          <li x-show="lang==='fr'">Suivi des depenses et rapports automatises</li>
          <li x-show="lang==='en'">Expense tracking and automated reports</li>
          <li x-show="lang==='fr'">Alertes budgetaires en temps reel</li>
          <li x-show="lang==='en'">Real-time budget alerts</li>
          <li x-show="lang==='fr'">Analyse financiere et tableaux de bord</li>
          <li x-show="lang==='en'">Financial analysis and dashboards</li>
        </ul>
      </div>
      <div class="use-case-card">
        <h3>&#x2699; IT / DevOps</h3>
        <ul>
          <li x-show="lang==='fr'">Revue de code automatisee et suggestions</li>
          <li x-show="lang==='en'">Automated code review and suggestions</li>
          <li x-show="lang==='fr'">Reponse aux incidents et diagnostic</li>
          <li x-show="lang==='en'">Incident response and diagnostics</li>
          <li x-show="lang==='fr'">Documentation technique automatique</li>
          <li x-show="lang==='en'">Automatic technical documentation</li>
        </ul>
      </div>
      <div class="use-case-card">
        <h3>&#x2696; <span x-show="lang==='fr'">Juridique</span><span x-show="lang==='en'">Legal</span></h3>
        <ul>
          <li x-show="lang==='fr'">Analyse de documents et contrats</li>
          <li x-show="lang==='en'">Document and contract analysis</li>
          <li x-show="lang==='fr'">Verification de conformite reglementaire</li>
          <li x-show="lang==='en'">Regulatory compliance checks</li>
          <li x-show="lang==='fr'">Recherche juridique assistee par IA</li>
          <li x-show="lang==='en'">AI-assisted legal research</li>
        </ul>
      </div>
      <div class="use-case-card">
        <h3>&#x1F4C8; <span x-show="lang==='fr'">Commercial</span><span x-show="lang==='en'">Sales</span></h3>
        <ul>
          <li x-show="lang==='fr'">Assistant CRM et suivi des opportunites</li>
          <li x-show="lang==='en'">CRM assistant and opportunity tracking</li>
          <li x-show="lang==='fr'">Qualification automatique des leads</li>
          <li x-show="lang==='en'">Automated lead qualification</li>
          <li x-show="lang==='fr'">Preparation de propositions commerciales</li>
          <li x-show="lang==='en'">Sales proposal preparation</li>
        </ul>
      </div>
      <div class="use-case-card">
        <h3>&#x1F4CB; <span x-show="lang==='fr'">Operations</span><span x-show="lang==='en'">Operations</span></h3>
        <ul>
          <li x-show="lang==='fr'">Comptes rendus de reunion automatiques</li>
          <li x-show="lang==='en'">Automatic meeting notes and summaries</li>
          <li x-show="lang==='fr'">Gestion de projet et suivi des taches</li>
          <li x-show="lang==='en'">Project management and task tracking</li>
          <li x-show="lang==='fr'">Automatisation des processus metier</li>
          <li x-show="lang==='en'">Business process automation</li>
        </ul>
      </div>
    </div>
  </div>
</section>

{{-- ========== PRE-BUILT AGENTS GRID ========== --}}
<section id="agents">
  <div class="section-inner">
    <span class="section-label">// agents</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">36+ agents IA <span class="gradient-text">specialises</span></span>
      <span x-show="lang==='en'">36+ specialized <span class="gradient-text">AI agents</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Chaque agent dispose de son propre systeme de prompt, acces aux outils, et memoire isolee. Le RouterAgent dispatch intelligemment chaque requete.</span>
      <span x-show="lang==='en'">Each agent has its own system prompt, tool access, and isolated memory. The RouterAgent intelligently dispatches every request.</span>
    </p>
    <div class="agents-grid">
      <div class="agent-chip"><div class="agent-emoji">&#x1F4AC;</div><div class="agent-info"><h4>ChatAgent</h4><p>General conversation & multimodal</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4BB;</div><div class="agent-info"><h4>DevAgent</h4><p>Code, GitHub & API automation</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x23F0;</div><div class="agent-info"><h4>ReminderAgent</h4><p>Time-based reminders & snooze</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4C1;</div><div class="agent-info"><h4>ProjectAgent</h4><p>Project management & stats</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4C8;</div><div class="agent-info"><h4>AnalysisAgent</h4><p>Data analysis & report generation</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x2705;</div><div class="agent-info"><h4>TodoAgent</h4><p>Task management & priorities</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F3B5;</div><div class="agent-info"><h4>MusicAgent</h4><p>Spotify search & playlists</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4B0;</div><div class="agent-info"><h4>FinanceAgent</h4><p>Expenses, budgets & alerts</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F60A;</div><div class="agent-info"><h4>MoodCheckAgent</h4><p>Emotional tracking & wellness</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F50D;</div><div class="agent-info"><h4>CodeReviewAgent</h4><p>Intelligent code analysis</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F9E0;</div><div class="agent-info"><h4>SmartContextAgent</h4><p>User behavior learning</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F399;</div><div class="agent-info"><h4>SmartMeetingAgent</h4><p>Meeting notes & transcription</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F3A4;</div><div class="agent-info"><h4>VoiceCommandAgent</h4><p>Audio transcription & commands</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4DA;</div><div class="agent-info"><h4>FlashcardAgent</h4><p>Spaced repetition learning</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4C5;</div><div class="agent-info"><h4>EventReminderAgent</h4><p>Calendar events & scheduling</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4F0;</div><div class="agent-info"><h4>ContentSummarizerAgent</h4><p>URL & content summarization</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4F7;</div><div class="agent-info"><h4>ScreenshotAgent</h4><p>OCR, annotation & comparison</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F3AF;</div><div class="agent-info"><h4>HabitAgent</h4><p>Habit tracking & streaks</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F345;</div><div class="agent-info"><h4>PomodoroAgent</h4><p>Focus timer & productivity</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4C4;</div><div class="agent-info"><h4>DocumentAgent</h4><p>PDF, Excel & document generation</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F3AE;</div><div class="agent-info"><h4>HangmanGameAgent</h4><p>Word games & entertainment</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F50D;</div><div class="agent-info"><h4>WebSearchAgent</h4><p>Real-time web search & API stats</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4B3;</div><div class="agent-info"><h4>BudgetTrackerAgent</h4><p>Personal finance & budgets</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4D6;</div><div class="agent-info"><h4>ContentCuratorAgent</h4><p>Reading lists & challenges</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F9E9;</div><div class="agent-info"><h4>InteractiveQuizAgent</h4><p>Dynamic quizzes & scoring</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4E7;</div><div class="agent-info"><h4>DailyBriefAgent</h4><p>Daily briefing compilation</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4CC;</div><div class="agent-info"><h4>ContextAgent</h4><p>Context memory management</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4AD;</div><div class="agent-info"><h4>ConversationMemoryAgent</h4><p>Conversation history tracking</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F373;</div><div class="agent-info"><h4>RecipeAgent</h4><p>Recipe recommendations</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F3B2;</div><div class="agent-info"><h4>GameMasterAgent</h4><p>RPG game master</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F91D;</div><div class="agent-info"><h4>CollaborativeTaskAgent</h4><p>Team task coordination</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x23F3;</div><div class="agent-info"><h4>TimeBlockerAgent</h4><p>Time blocking & scheduling</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x2699;</div><div class="agent-info"><h4>UserPreferencesAgent</h4><p>Preference management</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4CB;</div><div class="agent-info"><h4>ZenibizDocsAgent</h4><p>Photo to PDF conversion</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F916;</div><div class="agent-info"><h4>AIAssistantAgent</h4><p>Self-improving AI assistant</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F500;</div><div class="agent-info"><h4>RouterAgent</h4><p>Confidence-scored auto-dispatch</p></div></div>
    </div>
  </div>
</section>

{{-- ========== CUSTOM AGENTS / RAG ========== --}}
<section style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// custom agents</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Creez vos propres <span class="gradient-text">agents metier</span></span>
      <span x-show="lang==='en'">Build your own <span class="gradient-text">business agents</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Entrainez des agents IA sur vos documents internes grace au RAG. Vos employes obtiennent des reponses precises basees sur votre base de connaissances.</span>
      <span x-show="lang==='en'">Train AI agents on your internal documents using RAG. Your employees get precise answers based on your knowledge base.</span>
    </p>
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon purple">&#x1F4C2;</div>
        <h3 x-show="lang==='fr'">Importez vos documents</h3>
        <h3 x-show="lang==='en'">Import your documents</h3>
        <p x-show="lang==='fr'">PDF, Word, Excel, pages web. Indexation automatique et vectorisation pour une recherche semantique rapide.</p>
        <p x-show="lang==='en'">PDF, Word, Excel, web pages. Automatic indexing and vectorization for fast semantic search.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon blue">&#x1F3AF;</div>
        <h3 x-show="lang==='fr'">Reponses contextuelles</h3>
        <h3 x-show="lang==='en'">Contextual answers</h3>
        <p x-show="lang==='fr'">Retrieval-Augmented Generation : l'agent cite ses sources et ne fabrique jamais de reponses. Regles anti-hallucination integrees.</p>
        <p x-show="lang==='en'">Retrieval-Augmented Generation: the agent cites its sources and never fabricates answers. Built-in anti-hallucination rules.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon green">&#x1F512;</div>
        <h3 x-show="lang==='fr'">100% prive</h3>
        <h3 x-show="lang==='en'">100% private</h3>
        <p x-show="lang==='fr'">Vos documents restent sur vos serveurs. Combinez avec les LLMs on-prem pour une souverainete totale, sans aucun appel API externe.</p>
        <p x-show="lang==='en'">Your documents stay on your servers. Combine with on-prem LLMs for full sovereignty, with zero external API calls.</p>
      </div>
    </div>
  </div>
</section>

{{-- ========== TECHNOLOGY ========== --}}
<section id="technology" x-data="{ archMode: 'hybrid' }">
  <div class="section-inner">
    <span class="section-label">// technology</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Architecture <span class="gradient-text">enterprise-grade</span></span>
      <span x-show="lang==='en'">Enterprise-grade <span class="gradient-text">architecture</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Choisissez votre mode de deploiement. Full on-prem, full cloud ou hybride &mdash; la meme plateforme, votre infrastructure.</span>
      <span x-show="lang==='en'">Choose your deployment mode. Full on-prem, full cloud or hybrid &mdash; same platform, your infrastructure.</span>
    </p>

    {{-- Mode toggle --}}
    <div style="display: flex; gap: 4px; background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 4px; max-width: 500px; margin-bottom: 2rem;">
      <button @click="archMode = 'onprem'" :style="archMode === 'onprem' ? 'background: linear-gradient(135deg, #10b981, #059669); color: #fff;' : 'color: var(--text-muted);'"
              style="flex: 1; padding: 10px 16px; border: none; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: var(--font); transition: all 0.2s;">
        <span x-show="lang==='fr'">Full On-Prem</span><span x-show="lang==='en'">Full On-Prem</span>
      </button>
      <button @click="archMode = 'hybrid'" :style="archMode === 'hybrid' ? 'background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff;' : 'color: var(--text-muted);'"
              style="flex: 1; padding: 10px 16px; border: none; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: var(--font); transition: all 0.2s;">
        Hybride
      </button>
      <button @click="archMode = 'cloud'" :style="archMode === 'cloud' ? 'background: linear-gradient(135deg, #8b5cf6, #ec4899); color: #fff;' : 'color: var(--text-muted);'"
              style="flex: 1; padding: 10px 16px; border: none; border-radius: 10px; font-weight: 600; font-size: 0.85rem; cursor: pointer; font-family: var(--font); transition: all 0.2s;">
        Full Cloud
      </button>
    </div>

    {{-- Mode description --}}
    <div style="margin-bottom: 1.5rem; padding: 1rem 1.5rem; border-radius: 10px; font-size: 0.9rem;" :style="archMode === 'onprem' ? 'background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7;' : (archMode === 'cloud' ? 'background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.3); color: #c4b5fd;' : 'background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); color: #93c5fd;')">
      <template x-if="archMode === 'onprem'">
        <div>
          <strong x-show="lang==='fr'">100% On-Prem</strong><strong x-show="lang==='en'">100% On-Prem</strong>
          <span x-show="lang==='fr'"> &mdash; Zero donnee sortante. LLMs locaux via Ollama (Qwen, Mistral, Llama). Ideal conformite NIS2, RGPD, secteurs sensibles.</span>
          <span x-show="lang==='en'"> &mdash; Zero data leaving your servers. Local LLMs via Ollama (Qwen, Mistral, Llama). Ideal for NIS2, GDPR, sensitive sectors.</span>
        </div>
      </template>
      <template x-if="archMode === 'hybrid'">
        <div>
          <strong>Hybride</strong>
          <span x-show="lang==='fr'"> &mdash; LLMs locaux pour les taches courantes + Cloud (Claude, GPT-4) pour le raisonnement complexe. Le meilleur des deux mondes.</span>
          <span x-show="lang==='en'"> &mdash; Local LLMs for routine tasks + Cloud (Claude, GPT-4) for complex reasoning. Best of both worlds.</span>
        </div>
      </template>
      <template x-if="archMode === 'cloud'">
        <div>
          <strong x-show="lang==='fr'">Full Cloud</strong><strong x-show="lang==='en'">Full Cloud</strong>
          <span x-show="lang==='fr'"> &mdash; Puissance maximale avec Claude Opus/Sonnet et GPT-4. Pas besoin de GPU. Deploiement le plus simple.</span>
          <span x-show="lang==='en'"> &mdash; Maximum power with Claude Opus/Sonnet and GPT-4. No GPU needed. Simplest deployment.</span>
        </div>
      </template>
    </div>

    {{-- Mermaid diagram --}}
    <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 2rem; overflow-x: auto;">
      <div class="mermaid" x-show="archMode === 'onprem'" x-cloak>
graph LR
  subgraph Channels["Canaux"]
    WA["WhatsApp<br/>Baileys"]
    WEB["Web Chat<br/>Dashboard"]
    API["API REST"]
  end
  subgraph STACK["ZeniClaw Stack — Vos Serveurs"]
    GW["WhatsApp<br/>Gateway<br/>:3000"]
    APP["App Laravel 12<br/>PHP 8.4<br/>:8080"]
    ROUTER["RouterAgent<br/>Intent Classifier"]
    AGENTS["36+ Agents<br/>Agentic Loop<br/>RAG Engine"]
    CUSTOM["Custom Agents<br/>Skills & Scripts"]
    DB[("PostgreSQL 16<br/>Vectors + Memory")]
    CACHE[("Redis 7<br/>Queue + Cache")]
  end
  subgraph LLM["LLMs On-Prem"]
    OLLAMA["Ollama<br/>Qwen 2.5 | Mistral<br/>Llama | DeepSeek"]
  end
  WA <-->|QR| GW
  WEB --> APP
  API --> APP
  GW -->|webhook| APP
  APP --> ROUTER
  ROUTER --> AGENTS
  AGENTS --> CUSTOM
  AGENTS <-->|embeddings| OLLAMA
  CUSTOM <-->|inference| OLLAMA
  APP --- DB
  APP --- CACHE
  style STACK fill:#0a2e1a,stroke:#10b981,stroke-width:2px
  style LLM fill:#0a2e1a,stroke:#10b981,stroke-width:2px
  style Channels fill:#1a1f2e,stroke:#334155
  style OLLAMA fill:#065f46,stroke:#10b981,color:#fff
  style APP fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style DB fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style CACHE fill:#0f3a2a,stroke:#10b981,color:#fff
      </div>

      <div class="mermaid" x-show="archMode === 'hybrid'" x-cloak>
graph LR
  subgraph Channels["Canaux"]
    WA["WhatsApp<br/>Baileys"]
    WEB["Web Chat<br/>Dashboard"]
    API["API REST"]
  end
  subgraph STACK["ZeniClaw Stack — Vos Serveurs"]
    GW["WhatsApp<br/>Gateway<br/>:3000"]
    APP["App Laravel 12<br/>PHP 8.4<br/>:8080"]
    ROUTER["RouterAgent<br/>Intent Classifier"]
    AGENTS["36+ Agents<br/>Agentic Loop<br/>RAG Engine"]
    CUSTOM["Custom Agents<br/>Skills & Scripts"]
    DB[("PostgreSQL 16<br/>Vectors + Memory")]
    CACHE[("Redis 7<br/>Queue + Cache")]
  end
  subgraph LOCAL["LLMs On-Prem"]
    OLLAMA["Ollama<br/>Qwen 2.5 | Mistral"]
  end
  subgraph CLOUD["Cloud LLMs"]
    CLAUDE["Claude<br/>Opus | Sonnet | Haiku"]
    GPT["OpenAI<br/>GPT-4o"]
  end
  WA <-->|QR| GW
  WEB --> APP
  API --> APP
  GW -->|webhook| APP
  APP --> ROUTER
  ROUTER --> AGENTS
  AGENTS --> CUSTOM
  AGENTS <-->|"fast tasks"| OLLAMA
  AGENTS <-->|"complex reasoning"| CLAUDE
  AGENTS -.->|optional| GPT
  APP --- DB
  APP --- CACHE
  style STACK fill:#111827,stroke:#3b82f6,stroke-width:2px
  style LOCAL fill:#0a2e1a,stroke:#10b981,stroke-width:2px
  style CLOUD fill:#1a1035,stroke:#8b5cf6,stroke-width:2px
  style Channels fill:#1a1f2e,stroke:#334155
  style OLLAMA fill:#065f46,stroke:#10b981,color:#fff
  style CLAUDE fill:#3b1f7e,stroke:#8b5cf6,color:#fff
  style GPT fill:#3b1f7e,stroke:#8b5cf6,color:#fff
  style APP fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style DB fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style CACHE fill:#0f3a2a,stroke:#10b981,color:#fff
      </div>

      <div class="mermaid" x-show="archMode === 'cloud'" x-cloak>
graph LR
  subgraph Channels["Canaux"]
    WA["WhatsApp<br/>Baileys"]
    WEB["Web Chat<br/>Dashboard"]
    API["API REST"]
  end
  subgraph STACK["ZeniClaw Stack — Vos Serveurs"]
    GW["WhatsApp<br/>Gateway<br/>:3000"]
    APP["App Laravel 12<br/>PHP 8.4<br/>:8080"]
    ROUTER["RouterAgent<br/>Intent Classifier"]
    AGENTS["36+ Agents<br/>Agentic Loop<br/>RAG Engine"]
    CUSTOM["Custom Agents<br/>Skills & Scripts"]
    DB[("PostgreSQL 16<br/>Vectors + Memory")]
    CACHE[("Redis 7<br/>Queue + Cache")]
  end
  subgraph CLOUD["Cloud AI Providers"]
    CLAUDE["Claude<br/>Opus | Sonnet | Haiku"]
    GPT["OpenAI<br/>GPT-4o | GPT-4o-mini"]
    EMBED["Embeddings<br/>text-embedding-3"]
  end
  WA <-->|QR| GW
  WEB --> APP
  API --> APP
  GW -->|webhook| APP
  APP --> ROUTER
  ROUTER --> AGENTS
  AGENTS --> CUSTOM
  AGENTS <-->|inference| CLAUDE
  AGENTS <-->|fallback| GPT
  AGENTS <-->|RAG| EMBED
  APP --- DB
  APP --- CACHE
  style STACK fill:#111827,stroke:#3b82f6,stroke-width:2px
  style CLOUD fill:#1a1035,stroke:#8b5cf6,stroke-width:2px
  style Channels fill:#1a1f2e,stroke:#334155
  style CLAUDE fill:#3b1f7e,stroke:#8b5cf6,color:#fff
  style GPT fill:#3b1f7e,stroke:#8b5cf6,color:#fff
  style EMBED fill:#3b1f7e,stroke:#8b5cf6,color:#fff
  style APP fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style DB fill:#1e3a5f,stroke:#3b82f6,color:#fff
  style CACHE fill:#1e3a5f,stroke:#3b82f6,color:#fff
      </div>
    </div>

    <div class="tech-grid" style="margin-top: 2rem;">
      <div class="tech-item"><div class="tech-icon">&#x1F418;</div><span>PHP 8.4</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F3F0;</div><span>Laravel 12</span></div>
      <div class="tech-item"><div class="tech-icon">&#x26A1;</div><span>Livewire 3</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F3A8;</div><span>Tailwind CSS</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F5C3;</div><span>PostgreSQL 16</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F534;</div><span>Redis 7</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F433;</div><span>Podman / Docker</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F4F1;</div><span>Baileys Gateway</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F9E0;</div><span>Claude / GPT-4</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F5A5;</div><span>Ollama</span></div>
    </div>
  </div>
</section>

{{-- ========== DEPLOYMENT ========== --}}
<section id="deploy" style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// deploy</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Operationnel en <span class="gradient-text">5 minutes</span></span>
      <span x-show="lang==='en'">Up and running in <span class="gradient-text">5 minutes</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Un serveur Linux avec Podman ou Docker. Le script d'installation gere tout le reste.</span>
      <span x-show="lang==='en'">A Linux server with Podman or Docker. The install script handles everything else.</span>
    </p>
    <div class="install-steps">
      <div class="install-step">
        <div class="step-number">1</div>
        <div class="step-content">
          <h3 x-show="lang==='fr'">Clonez le depot</h3>
          <h3 x-show="lang==='en'">Clone the repository</h3>
          <p x-show="lang==='fr'">Recuperez le code source et lancez l'installateur interactif.</p>
          <p x-show="lang==='en'">Fetch the source code and run the interactive installer.</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button>git clone https://github.com/zeniclaw/core.git ~/zeniclaw<br>cd ~/zeniclaw</div>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">2</div>
        <div class="step-content">
          <h3 x-show="lang==='fr'">Lancez l'installation</h3>
          <h3 x-show="lang==='en'">Run the installer</h3>
          <p x-show="lang==='fr'">Le script configure automatiquement les 5 conteneurs (app, base de donnees, cache, gateway, LLM).</p>
          <p x-show="lang==='en'">The script automatically configures all 5 containers (app, database, cache, gateway, LLM).</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button>sudo bash install.sh<br><span class="comment"># 5 containers: app, postgres, redis, gateway, ollama</span></div>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">3</div>
        <div class="step-content">
          <h3 x-show="lang==='fr'">Ouvrez votre navigateur</h3>
          <h3 x-show="lang==='en'">Open your browser</h3>
          <p x-show="lang==='fr'">Accedez au tableau de bord, configurez vos agents et connectez vos canaux (WhatsApp, Web Chat, API).</p>
          <p x-show="lang==='en'">Access the dashboard, configure your agents and connect your channels (WhatsApp, Web Chat, API).</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button>open <span style="color: var(--accent-blue);">http://localhost:8080</span><br><span class="comment"># Default: admin@zeniclaw.io / password</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ========== SECURITY & COMPLIANCE ========== --}}
<section>
  <div class="section-inner">
    <span class="section-label">// security</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Securite et <span class="gradient-text">conformite</span></span>
      <span x-show="lang==='en'">Security & <span class="gradient-text">compliance</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Concu pour les exigences de securite des entreprises. Vos donnees ne quittent jamais vos serveurs.</span>
      <span x-show="lang==='en'">Built for enterprise security requirements. Your data never leaves your servers.</span>
    </p>
    <div class="security-grid">
      <div class="security-item">
        <div class="security-icon">&#x1F512;</div>
        <div>
          <h4 x-show="lang==='fr'">Souverainete des donnees</h4>
          <h4 x-show="lang==='en'">Data sovereignty</h4>
          <p x-show="lang==='fr'">Deploiement 100% on-prem. Aucune donnee ne transite par des serveurs tiers. Compatible air-gap avec LLMs locaux.</p>
          <p x-show="lang==='en'">100% on-prem deployment. No data passes through third-party servers. Air-gap compatible with local LLMs.</p>
        </div>
      </div>
      <div class="security-item">
        <div class="security-icon">&#x1F510;</div>
        <div>
          <h4 x-show="lang==='fr'">Chiffrement AES-256</h4>
          <h4 x-show="lang==='en'">AES-256 Encryption</h4>
          <p x-show="lang==='fr'">Tous les secrets et donnees sensibles sont chiffres au repos. Communications HTTPS/TLS en transit.</p>
          <p x-show="lang==='en'">All secrets and sensitive data encrypted at rest. HTTPS/TLS communications in transit.</p>
        </div>
      </div>
      <div class="security-item">
        <div class="security-icon">&#x1F465;</div>
        <div>
          <h4>RBAC</h4>
          <p x-show="lang==='fr'">Controle d'acces base sur les roles : superadmin, admin, operateur, lecteur. Permissions granulaires par agent.</p>
          <p x-show="lang==='en'">Role-based access control: superadmin, admin, operator, viewer. Granular permissions per agent.</p>
        </div>
      </div>
      <div class="security-item">
        <div class="security-icon">&#x1F4DD;</div>
        <div>
          <h4 x-show="lang==='fr'">Journaux d'audit</h4>
          <h4 x-show="lang==='en'">Audit logs</h4>
          <p x-show="lang==='fr'">Tracabilite complete de chaque action, decision d'agent, et acces utilisateur. Export pour conformite.</p>
          <p x-show="lang==='en'">Complete traceability of every action, agent decision, and user access. Export for compliance.</p>
        </div>
      </div>
      <div class="security-item">
        <div class="security-icon">&#x1F6E1;</div>
        <div>
          <h4 x-show="lang==='fr'">Sandboxing des agents</h4>
          <h4 x-show="lang==='en'">Agent sandboxing</h4>
          <p x-show="lang==='fr'">Chaque agent s'execute dans un environnement isole avec memoire et outils dedies. Aucun acces croise non autorise.</p>
          <p x-show="lang==='en'">Each agent runs in an isolated environment with dedicated memory and tools. No unauthorized cross-access.</p>
        </div>
      </div>
      <div class="security-item">
        <div class="security-icon">&#x1F504;</div>
        <div>
          <h4 x-show="lang==='fr'">Mises a jour et monitoring</h4>
          <h4 x-show="lang==='en'">Updates & monitoring</h4>
          <p x-show="lang==='fr'">Mises a jour automatiques, health checks, et watchdog integre pour une disponibilite maximale.</p>
          <p x-show="lang==='en'">Automatic updates, health checks, and built-in watchdog for maximum uptime.</p>
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ========== CTA / CONTACT ========== --}}
<section id="contact" style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// contact</span>
    <h2 class="section-title">
      <span x-show="lang==='fr'">Parlons de votre <span class="gradient-text">projet</span></span>
      <span x-show="lang==='en'">Let's discuss your <span class="gradient-text">project</span></span>
    </h2>
    <p class="section-desc">
      <span x-show="lang==='fr'">Une question, une demo, un projet d'integration ? Contactez-nous.</span>
      <span x-show="lang==='en'">A question, a demo, an integration project? Get in touch.</span>
    </p>

    <div style="max-width: 600px; margin: 0 auto;">

      {{-- Founder card --}}
      <div style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 2rem; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.5rem;">
          <img src="https://www.zenibiz.com/guillaume_tilleul.jpg" alt="Guillaume Tilleul" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 2px solid var(--border);">
          <div>
            <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 2px;">Guillaume Tilleul</h3>
            <p style="font-size: 0.85rem; color: var(--accent-blue); font-weight: 600; margin-bottom: 4px;">
              <span x-show="lang==='fr'">Fondateur &mdash; ZeniBiz</span>
              <span x-show="lang==='en'">Founder &mdash; ZeniBiz</span>
            </p>
            <p style="font-size: 0.8rem; color: var(--text-muted);">
              <span x-show="lang==='fr'">Entrepreneur et architecte de solutions IA pour les entreprises. Passionné par le déploiement on-prem et la souveraineté des données.</span>
              <span x-show="lang==='en'">Entrepreneur and AI solution architect for businesses. Passionate about on-prem deployment and data sovereignty.</span>
            </p>
          </div>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
          <a href="mailto:gti@zenibiz.com" class="btn btn-secondary" style="flex: 1; justify-content: center; min-width: 180px;">
            &#x2709; gti@zenibiz.com
          </a>
          <a href="tel:+32484885871" class="btn btn-secondary" style="flex: 1; justify-content: center; min-width: 180px;">
            &#x1F4F1; +32 484 88 58 71
          </a>
          <a href="https://www.zenibiz.com/nos-porteurs" target="_blank" class="btn btn-secondary" style="flex: 1; justify-content: center; min-width: 180px;">
            &#x1F310; zenibiz.com
          </a>
        </div>
      </div>

      {{-- WhatsApp Community --}}
      <a href="https://chat.whatsapp.com/G1ENranBGq63FYToMpcnYR" target="_blank" rel="noopener"
         style="display: flex; align-items: center; gap: 1rem; background: linear-gradient(135deg, #25D366, #128C7E); border-radius: var(--radius); padding: 1.25rem 1.5rem; text-decoration: none; color: #fff; transition: opacity 0.2s;"
         onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
        <span style="font-size: 2rem;">&#x1F4AC;</span>
        <div>
          <div style="font-weight: 700; font-size: 1rem; margin-bottom: 2px;">
            <span x-show="lang==='fr'">Communaute WhatsApp ZeniClaw</span>
            <span x-show="lang==='en'">ZeniClaw WhatsApp Community</span>
          </div>
          <div style="font-size: 0.8rem; opacity: 0.9;">
            <span x-show="lang==='fr'">Rejoignez la communaute &mdash; aide, idees, mises a jour</span>
            <span x-show="lang==='en'">Join the community &mdash; help, ideas, updates</span>
          </div>
        </div>
        <span style="margin-left: auto; font-size: 1.2rem;">&rarr;</span>
      </a>

    </div>
  </div>
</section>

{{-- ========== FOOTER ========== --}}
<footer>
  <div class="footer-inner">
    <ul class="footer-links">
      <li><a href="#solutions">Solutions</a></li>
      <li><a href="#agents">Agents</a></li>
      <li><a href="#technology" x-show="lang==='fr'">Technologie</a><a href="#technology" x-show="lang==='en'">Technology</a></li>
      <li><a href="#deploy" x-show="lang==='fr'">Installation</a><a href="#deploy" x-show="lang==='en'">Install</a></li>
      <li><a href="#contact">Contact</a></li>
      <li><a href="https://github.com/zeniclaw/core" target="_blank">GitHub</a></li>
      <li><a href="https://www.zenibiz.com" target="_blank">ZeniBiz</a></li>
      @if(!\App\Http\Middleware\BlockAuthOnOfficialDomain::isOfficialDomain())
        @auth
          <li><a href="{{ route('dashboard') }}">Dashboard</a></li>
        @else
          <li><a href="{{ route('login') }}">Sign In</a></li>
        @endauth
      @endif
    </ul>
    <p class="footer-copy">&copy; 2026 ZeniClaw by ZeniBiz &mdash;
      <span x-show="lang==='fr'">Plateforme IA d'entreprise on-prem</span>
      <span x-show="lang==='en'">Enterprise AI platform, on your servers</span>
    </p>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
<script>
mermaid.initialize({
  startOnLoad: false,
  theme: 'dark',
  themeVariables: {
    primaryColor: '#1e3a5f',
    primaryTextColor: '#f1f5f9',
    primaryBorderColor: '#3b82f6',
    lineColor: '#475569',
    secondaryColor: '#1a1035',
    tertiaryColor: '#0a2e1a',
    fontFamily: 'Inter, system-ui, sans-serif',
    fontSize: '13px',
  },
  flowchart: { curve: 'basis', padding: 20 },
});
// Re-render mermaid when Alpine changes the diagram
async function renderMermaid() {
  document.querySelectorAll('.mermaid[data-processed]').forEach(el => {
    el.removeAttribute('data-processed');
    el.innerHTML = el.getAttribute('data-original') || el.innerHTML;
  });
  // Save originals
  document.querySelectorAll('.mermaid:not([data-original])').forEach(el => {
    el.setAttribute('data-original', el.innerHTML);
  });
  await mermaid.run({ querySelector: '.mermaid' });
}
// Initial render + re-render on mode change
document.addEventListener('alpine:initialized', () => {
  setTimeout(renderMermaid, 100);
});
// Watch for x-show changes
const observer = new MutationObserver(() => setTimeout(renderMermaid, 50));
document.addEventListener('DOMContentLoaded', () => {
  const techSection = document.getElementById('technology');
  if (techSection) observer.observe(techSection, { attributes: true, subtree: true, attributeFilter: ['style'] });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script>
function copyCode(btn) {
  const block = btn.parentElement;
  const text = block.innerText.replace('Copy', '').replace('Copied!', '').trim();
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => {
      btn.textContent = 'Copied!';
      setTimeout(() => btn.textContent = 'Copy', 2000);
    });
  } else {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  }
}
const fadeObserver = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) e.target.style.animationPlayState = 'running'; });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => { el.style.animationPlayState = 'paused'; fadeObserver.observe(el); });
</script>
</body>
</html>
