<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

{{-- SEO --}}
<title>ZeniClaw — Plateforme IA WhatsApp Self-Hosted | 23 Agents Autonomes</title>
<meta name="description" content="ZeniClaw est une plateforme IA open source et self-hosted avec 23 agents specialises. Gerez vos projets, finances, rappels et plus depuis un simple chat WhatsApp. On-prem ou Cloud, propulse par Claude, GPT-4 et Ollama.">
<meta name="keywords" content="zeniclaw, whatsapp ia, chatbot whatsapp, ai agents, self-hosted, open source, claude, gpt-4, ollama, on-prem, assistant ia, automatisation whatsapp, crm whatsapp">
<meta name="author" content="ZeniBiz">
<meta name="robots" content="index, follow">
<link rel="canonical" href="{{ url('/') }}">

{{-- Open Graph (Facebook, Instagram, WhatsApp, LinkedIn) --}}
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url('/') }}">
<meta property="og:title" content="ZeniClaw — Your AI Army, One WhatsApp Away">
<meta property="og:description" content="Plateforme IA self-hosted avec 23 agents autonomes. Projets, finances, rappels, code reviews, flashcards — tout depuis WhatsApp. On-prem ou Cloud. Open source et gratuit.">
<meta property="og:image" content="{{ url('/og-image.php') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="image/png">
<meta property="og:site_name" content="ZeniClaw">
<meta property="og:locale" content="fr_FR">

{{-- Twitter Card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="ZeniClaw — Your AI Army, One WhatsApp Away">
<meta name="twitter:description" content="Plateforme IA self-hosted avec 23 agents autonomes. Projets, finances, rappels, code reviews — tout depuis WhatsApp.">
<meta name="twitter:image" content="{{ url('/og-image.php') }}">

{{-- Favicon --}}
<link rel="icon" href="/favicon.ico" type="image/x-icon">

{{-- Structured Data (JSON-LD) --}}
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "SoftwareApplication",
  "name": "ZeniClaw",
  "description": "Plateforme IA open source et self-hosted avec 23 agents specialises accessibles via WhatsApp. On-prem ou Cloud.",
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
  "featureList": "23 AI Agents, WhatsApp Integration, Self-Hosted, Open Source, On-Prem LLM, Ollama, Agentic Loop, Web Search, API Tracking, Confidence Routing, Project Management, Finance Tracking, Code Reviews, Meeting Notes, Flashcards, Reminders, Document Generation, Games, Persistent Memory"
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
.nav-links { display: flex; gap: 2rem; list-style: none; align-items: center; }
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
.btn-whatsapp { background: #25D366; color: #fff; }
.btn-whatsapp:hover { background: #1ebe5d; transform: translateY(-1px); }

.community-banner {
  background: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
  padding: 1.25rem 2rem; text-align: center;
}
.community-banner a {
  color: #fff; text-decoration: none; font-weight: 600; font-size: 1rem;
  display: inline-flex; align-items: center; gap: 10px;
}
.community-banner a:hover { text-decoration: underline; }
.community-banner .arrow { transition: transform 0.2s; }
.community-banner a:hover .arrow { transform: translateX(4px); }

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
.feature-new {
  position: absolute; top: 12px; right: 12px;
  font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
  padding: 3px 8px; border-radius: 4px; background: var(--accent-green); color: #fff;
}

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

.tech-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
.tech-item {
  display: flex; align-items: center; gap: 12px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px; transition: all 0.2s;
}
.tech-item:hover { border-color: rgba(59,130,246,0.3); }
.tech-icon { font-size: 1.5rem; width: 40px; text-align: center; }
.tech-item span { font-size: 0.9rem; font-weight: 500; }

.changelog { max-width: 700px; }
.changelog-item {
  display: flex; gap: 1.25rem; padding: 1.5rem 0;
  border-bottom: 1px solid var(--border);
}
.changelog-item:last-child { border-bottom: none; }
.changelog-version {
  flex-shrink: 0; width: 72px;
  font-family: var(--mono); font-size: 0.85rem; font-weight: 600;
  color: var(--accent-blue); padding-top: 2px;
}
.changelog-content h4 { font-size: 1rem; font-weight: 600; margin-bottom: 0.4rem; }
.changelog-content p { font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6; }
.changelog-tag {
  display: inline-block; font-size: 0.65rem; font-weight: 600; text-transform: uppercase;
  padding: 2px 6px; border-radius: 3px; margin-right: 6px; letter-spacing: 0.03em;
}
.tag-feat { background: rgba(16,185,129,0.15); color: var(--accent-green); }
.tag-fix { background: rgba(245,158,11,0.15); color: var(--accent-amber); }

footer {
  border-top: 1px solid var(--border); padding: 3rem 2rem; text-align: center;
}
.footer-inner { max-width: 1200px; margin: 0 auto; }
.footer-links { display: flex; justify-content: center; gap: 2rem; margin-bottom: 1.5rem; list-style: none; }
.footer-links a { color: var(--text-muted); text-decoration: none; font-size: 0.85rem; transition: color 0.2s; }
.footer-links a:hover { color: var(--text-primary); }
.footer-copy { color: var(--text-muted); font-size: 0.8rem; }

@media (max-width: 768px) {
  .nav-links { display: none; }
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .install-step { grid-template-columns: 40px 1fr; gap: 1rem; }
  .step-number { width: 40px; height: 40px; font-size: 0.95rem; }
  section { padding: 60px 1.25rem; }
}

@keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.fade-up { opacity: 0; animation: fadeUp 0.6s ease-out forwards; }
.fade-up.d1 { animation-delay: 0.1s; } .fade-up.d2 { animation-delay: 0.2s; }
.fade-up.d3 { animation-delay: 0.3s; } .fade-up.d4 { animation-delay: 0.4s; }
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <a href="/" class="nav-logo">
      <div class="nav-logo-icon">Z</div>
      ZeniClaw
    </a>
    <ul class="nav-links">
      <li><a href="#features">Features</a></li>
      <li><a href="#agents">Agents</a></li>
      <li><a href="#architecture">Architecture</a></li>
      <li><a href="#changelog">Changelog</a></li>
      <li><a href="#install">Install</a></li>
      @auth
        <li><a href="{{ route('dashboard') }}" class="nav-cta">Dashboard</a></li>
      @else
        <li><a href="{{ route('login') }}" class="nav-cta">Sign In</a></li>
      @endauth
    </ul>
  </div>
</nav>

<div class="community-banner" style="margin-top: 64px;">
  <a href="https://chat.whatsapp.com/G1ENranBGq63FYToMpcnYR" target="_blank" rel="noopener">
    &#x1F4AC; Join the ZeniClaw WhatsApp Community &mdash; Get help, share ideas, stay updated
    <span class="arrow">&rarr;</span>
  </a>
</div>

<section class="hero" style="padding-top: 80px;">
  <div class="hero-content">
    <div class="hero-badge fade-up">
      <span class="dot"></span>
      <span>v2.26 &middot; Open Source &middot; Self-Hosted &middot; On-Prem Ready</span>
    </div>
    <h1 class="fade-up d1">
      Your AI Army,<br>
      <span class="gradient-text">One WhatsApp Away</span>
    </h1>
    <p class="fade-up d2">
      ZeniClaw is a self-hosted AI platform with 23 specialized agents that turn your WhatsApp into an autonomous command center. Cloud or On-Prem LLMs, agentic tool loops, persistent memory &mdash; all from a single chat.
    </p>
    <div class="hero-actions fade-up d3">
      @auth
        <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to Dashboard</a>
      @else
        <a href="{{ route('login') }}" class="btn btn-primary">Sign In</a>
        @if (Route::has('register'))
          <a href="{{ route('register') }}" class="btn btn-secondary">Create Account</a>
        @endif
      @endauth
      <a href="https://chat.whatsapp.com/G1ENranBGq63FYToMpcnYR" class="btn btn-whatsapp" target="_blank" rel="noopener">&#x1F4AC; Join Community</a>
      <a href="#install" class="btn btn-outline">&lt;/&gt; Install Guide</a>
    </div>
    <div class="hero-terminal fade-up d4">
      <div class="terminal-bar">
        <div class="terminal-dot r"></div>
        <div class="terminal-dot y"></div>
        <div class="terminal-dot g"></div>
      </div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">git clone https://gitlab.com/zenidev/zeniclaw.git</span></div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">cd zeniclaw && bash install.sh</span></div>
      <div class="terminal-line"><span class="comment"># 5 containers: app, db, redis, waha, ollama</span></div>
      <div class="terminal-line"><span class="prompt">$</span> <span class="cmd">open</span> <span class="url">http://localhost:8080</span></div>
    </div>
  </div>
</section>

<section style="padding-top: 0;">
  <div class="section-inner">
    <div class="stats-row">
      <div class="stat-box fade-up"><div class="stat-number">23</div><div class="stat-label">AI Agents</div></div>
      <div class="stat-box fade-up d1"><div class="stat-number">3+</div><div class="stat-label">LLM Providers</div></div>
      <div class="stat-box fade-up d2"><div class="stat-number">5</div><div class="stat-label">Containers</div></div>
      <div class="stat-box fade-up d3"><div class="stat-number">100%</div><div class="stat-label">Self-Hosted</div></div>
    </div>
  </div>
</section>

<section id="features">
  <div class="section-inner">
    <span class="section-label">// features</span>
    <h2 class="section-title">Everything you need, <span class="gradient-text">built in</span></h2>
    <p class="section-desc">A full-stack AI platform that goes way beyond chatbots. Every feature runs on your own infrastructure, your data never leaves your server.</p>
    <div class="features-grid">
      <div class="feature-card"><div class="feature-icon blue">&#x1F916;</div><h3>23 Autonomous Agents</h3><p>Specialized AI agents that handle tasks end-to-end: code reviews, project management, finance tracking, web search, document generation, meeting notes, habits, games, and more. Each agent has its own memory and tools.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon purple">&#x1F500;</div><h3>Agentic Loop</h3><p>LLM-driven decision loop with tool usage. Agents autonomously decide which tools to call, chain API operations, and iterate up to 10 times to complete complex tasks without human intervention.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon cyan">&#x1F5A5;</div><h3>On-Prem LLMs (Ollama)</h3><p>Run models locally with the built-in Ollama container. Download Qwen 2.5, CodeLlama, or any model directly from the UI. Zero API costs, full privacy, no data leaves your server.</p></div>
      <div class="feature-card"><div class="feature-icon green">&#x1F4AC;</div><h3>WhatsApp + Web Chat</h3><p>Native Baileys integration &mdash; no paid APIs. Full multimodal support: text, images, voice, PDFs. Plus a built-in web chat panel on the dashboard for instant testing.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon pink">&#x1F9E0;</div><h3>Persistent User Knowledge</h3><p>Per-user fact storage that survives across sessions. Agents remember your preferences, past API results, and project details. Recall before asking, store after learning.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon amber">&#x1F3AF;</div><h3>Model Selection per Agent</h3><p>Assign different LLMs to different sub-agents. Use Claude Opus for complex analysis, Haiku for routing, and Qwen on-prem for everyday chat. Full control over cost and quality.</p></div>
      <div class="feature-card"><div class="feature-icon red">&#x1F6E0;</div><h3>GitLab DevOps</h3><p>SubAgents clone repos, create branches, write code with Claude Code CLI, auto-commit, push, and open merge requests. Full CI/CD pipeline from a WhatsApp message.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon blue">&#x1F310;</div><h3>Claude-Driven API Agent</h3><p>Claude autonomously decides which HTTP calls to make, chains multi-step API requests, extracts and stores results. No hardcoded endpoints &mdash; pure LLM-driven API interaction.</p></div>
      <div class="feature-card"><div class="feature-icon purple">&#x1F512;</div><h3>Self-Hosted & Secure</h3><p>100% on-premise. AES-256 encrypted secrets, role-based access (superadmin/admin/operator/viewer), agent sandboxing, auto-updates, and health monitoring with watchdog.</p></div>
      <div class="feature-card"><div class="feature-icon green">&#x1F4CA;</div><h3>Finance Tracker</h3><p>Track expenses, manage budgets, get financial analytics and alerts. Categorize spending, set thresholds, and receive WhatsApp notifications when limits are reached.</p></div>
      <div class="feature-card"><div class="feature-icon pink">&#x1F4DD;</div><h3>Smart Meetings</h3><p>Record meetings, generate transcriptions, produce structured summaries with action items. Never miss a decision or follow-up again.</p></div>
      <div class="feature-card"><span class="feature-new">New</span><div class="feature-icon amber">&#x1F504;</div><h3>Self-Improving Agents</h3><p>Agents analyze conversations, detect missing capabilities, generate improvement proposals, and auto-implement them via SubAgents. Your platform gets smarter over time.</p></div>
    </div>
  </div>
</section>

<section id="agents" style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// agents</span>
    <h2 class="section-title">23 Specialized <span class="gradient-text">AI Agents</span></h2>
    <p class="section-desc">Each agent is purpose-built with its own system prompt, tool access, and isolated memory. The Router Agent uses confidence scoring and conversation history for intelligent contextual dispatch.</p>
    <div class="agents-grid">
      <div class="agent-chip"><div class="agent-emoji">&#x1F4AC;</div><div class="agent-info"><h4>ChatAgent</h4><p>General conversation & multimodal</p></div></div>
      <div class="agent-chip"><div class="agent-emoji">&#x1F4BB;</div><div class="agent-info"><h4>DevAgent</h4><p>Code, GitLab & API automation</p></div></div>
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
      <div class="agent-chip"><div class="agent-emoji">&#x1F500;</div><div class="agent-info"><h4>RouterAgent</h4><p>Confidence-scored auto-dispatch</p></div></div>
    </div>
  </div>
</section>

<section id="architecture">
  <div class="section-inner">
    <span class="section-label">// architecture</span>
    <h2 class="section-title">How it <span class="gradient-text">works</span></h2>
    <p class="section-desc">Five containers (Podman or Docker), zero external dependencies. Cloud LLMs or fully on-prem with Ollama &mdash; your choice.</p>
    <div class="arch-diagram">
<pre>
  <span class="hl-green">WhatsApp</span>                 <span class="hl-blue">ZeniClaw Stack</span>                    <span class="hl-purple">AI Providers</span>

  +-----------+       +----------------------------------+       +-------------+
  |           |  QR   |  <span class="hl-green">WhatsApp Gateway</span> (port 3000)    |       |  <span class="hl-purple">Claude</span>     |
  |  Phone    |&lt;-----&gt;|  Baileys + Express               |       |  Opus/Sonnet|
  |           |       |  Auto-reconnect, webhook relay    |       |  Haiku      |
  +-----------+       +-----------|----------------------+       +-------------+
                                  | webhook POST                        ^
  +-----------+                   v                                     |
  |  <span class="hl-blue">Web Chat</span> |       +----------------------------------+       +-------------+
  |  Dashboard|------&gt;|  <span class="hl-blue">App</span> (port 8080)                |       |  <span class="hl-purple">OpenAI</span>    |
  |  Panel    |       |  Laravel 12 + PHP 8.4            |------&gt;|  GPT-4o     |
  +-----------+       |                                  |       +-------------+
                      |  <span class="hl-amber">RouterAgent</span> --&gt; 23 Agents      |
                      |  <span class="hl-amber">Agentic Loop</span> (tool chaining)   |       +-------------+
                      |  <span class="hl-amber">SubAgents</span> (Claude Code CLI)    |       |  <span class="hl-cyan">Ollama</span>    |
                      |  <span class="hl-amber">Knowledge Store</span> (per-user)     |&lt;-----&gt;|  Qwen 2.5  |
                      |  Dashboard + Admin UI            |       |  CodeLlama  |
                      +-----------|----------------------+       +-------------+
                                  |
                      +-----------|-----------+          +-------------+
                      |           v           |          |  <span class="hl-pink">GitLab</span>    |
                +----------+  +---------+                |  Repos, MRs |
                | <span class="hl-blue">Postgres</span> |  |  <span class="hl-green">Redis</span>  |                +-------------+
                |  16      |  |  7      |
                |  30 models|  |  Queue  |
                |  Memory   |  |  Cache  |
                +----------+  +---------+
</pre>
    </div>
    <div class="tech-grid" style="margin-top: 2rem;">
      <div class="tech-item"><div class="tech-icon">&#x1F418;</div><span>PHP 8.4</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F3F0;</div><span>Laravel 12</span></div>
      <div class="tech-item"><div class="tech-icon">&#x26A1;</div><span>Livewire 3</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F3A8;</div><span>Tailwind CSS</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F5C3;</div><span>PostgreSQL 16</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F534;</div><span>Redis 7</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F433;</div><span>Podman / Docker</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F4F1;</div><span>Baileys (WAHA)</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F9E0;</div><span>Claude API</span></div>
      <div class="tech-item"><div class="tech-icon">&#x1F5A5;</div><span>Ollama</span></div>
    </div>
  </div>
</section>

<section id="changelog" style="background: var(--bg-secondary);">
  <div class="section-inner">
    <span class="section-label">// changelog</span>
    <h2 class="section-title">What's <span class="gradient-text">new</span></h2>
    <p class="section-desc">Recent updates and improvements to the platform.</p>
    <div class="changelog">
      <div class="changelog-item">
        <div class="changelog-version">v2.26</div>
        <div class="changelog-content">
          <h4>Agent Auto-Improvements, DocumentAgent & HangmanGameAgent</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span><strong>23 agents</strong> &mdash; added <strong>DocumentAgent</strong> (PDF, Excel generation) and <strong>HangmanGameAgent</strong> (word games) to the roster.<br>
            <span class="changelog-tag tag-feat">feat</span>Auto-improved <strong>MoodCheckAgent</strong>, <strong>ReminderAgent</strong>, <strong>SmartMeetingAgent</strong>, <strong>TodoAgent</strong> and <strong>WebSearchAgent</strong> with richer prompts and expanded capabilities.<br>
            <span class="changelog-tag tag-feat">feat</span>Updated homepage with all 23 agents, refreshed SEO metadata and architecture diagram.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.25</div>
        <div class="changelog-content">
          <h4>WebSearchAgent, Smart Routing & Agent Visibility</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>New <strong>WebSearchAgent</strong> &mdash; real-time web search via Brave Search API with full API usage tracking, stats dashboard, and cross-agent integration.<br>
            <span class="changelog-tag tag-feat">feat</span>ChatAgent can now autonomously search the web via the <code>web_search</code> tool in the agentic loop.<br>
            <span class="changelog-tag tag-feat">feat</span><strong>Confidence-scored routing</strong> &mdash; Router now returns 0-100 confidence, auto-fallbacks to chat below 50%, with deterministic fast-paths for 8+ common patterns (no LLM call needed).<br>
            <span class="changelog-tag tag-feat">feat</span>Disambiguation rules for commonly confused agents (code_review vs analysis, reminder vs event_reminder, etc.).<br>
            <span class="changelog-tag tag-feat">feat</span>Agent badge on each message in conversation view &mdash; see which sub-agent handled every response.<br>
            <span class="changelog-tag tag-feat">feat</span>Brave Search API key configurable from Settings page.<br>
            <span class="changelog-tag tag-feat">feat</span>Auto-improve test reports now include user prompt examples for each capability.<br>
            <span class="changelog-tag tag-fix">fix</span>Document agent responses (XLS/PDF creation) now visible in conversation history.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.24</div>
        <div class="changelog-content">
          <h4>Cross-Agent Data Sharing & Full On-Prem Ollama Support</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>Cross-agent data sharing &mdash; agents can exchange context and delegate tasks.<br>
            <span class="changelog-tag tag-feat">feat</span>Full on-prem support with Ollama integration, no external API required.<br>
            <span class="changelog-tag tag-feat">feat</span>Auto-improve agent system &mdash; continuous background self-improvement of all sub-agents.<br>
            <span class="changelog-tag tag-feat">feat</span>Debug tab in conversation view with routing decisions and agent logs.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.22</div>
        <div class="changelog-content">
          <h4>On-Prem LLMs, Persistent Knowledge & Model Selection</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>Built-in Ollama container for on-prem LLMs (Qwen 2.5, Coder models) with one-click download from the UI.<br>
            <span class="changelog-tag tag-feat">feat</span>Per-user persistent knowledge store &mdash; agents remember facts across sessions and check before asking.<br>
            <span class="changelog-tag tag-feat">feat</span>Model selection per sub-agent: assign Claude, GPT-4, or on-prem models to individual agents.<br>
            <span class="changelog-tag tag-feat">feat</span>AnthropicClient auto-routes non-Claude models to Ollama/vLLM OpenAI-compatible API.<br>
            <span class="changelog-tag tag-fix">fix</span>Self-improvement "bonne idee" spam &mdash; only notifies on genuine new capabilities.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.21</div>
        <div class="changelog-content">
          <h4>Agentic API Loop</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>Claude autonomously chains multiple API calls in a loop, analyzing results and deciding next steps without human intervention.<br>
            <span class="changelog-tag tag-feat">feat</span>Multi-call analysis: the LLM reviews combined API data and produces a synthesized answer.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.20</div>
        <div class="changelog-content">
          <h4>Smart Project Switching & Claude-Driven API Agent</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>Smart project switching via natural language &mdash; Claude understands "switch to project X".<br>
            <span class="changelog-tag tag-feat">feat</span>Web chat SubAgent feedback with real-time status polling.<br>
            <span class="changelog-tag tag-feat">feat</span>100% Claude-driven API agent &mdash; zero hardcoded fields, pure LLM interaction.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.19</div>
        <div class="changelog-content">
          <h4>Intelligent Contextual Routing</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>Conversation history injected into the router for contextual multi-turn intelligence.<br>
            <span class="changelog-tag tag-feat">feat</span>Generic API interaction for DevAgent &mdash; any REST API, zero config.<br>
            <span class="changelog-tag tag-fix">fix</span>Web chat "no response" bug fixed &mdash; dispatched results now carry the reply.
          </p>
        </div>
      </div>
      <div class="changelog-item">
        <div class="changelog-version">v2.18</div>
        <div class="changelog-content">
          <h4>Full LLM Router & Smart DevAgent</h4>
          <p>
            <span class="changelog-tag tag-feat">feat</span>DevAgent upgraded with GitLab integration and smart commands.<br>
            <span class="changelog-tag tag-feat">feat</span>Manager heartbeat for instance monitoring.<br>
            <span class="changelog-tag tag-feat">feat</span>Integrated web chat panel on the dashboard.<br>
            <span class="changelog-tag tag-fix">fix</span>Supervisor auto-restart for queue workers, container build failure protection.
          </p>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="install">
  <div class="section-inner">
    <span class="section-label">// install</span>
    <h2 class="section-title">Up and running in <span class="gradient-text">5 minutes</span></h2>
    <p class="section-desc">All you need is a Linux server with Podman or Docker. The install script handles everything else.</p>
    <div class="install-steps">
      <div class="install-step">
        <div class="step-number">1</div>
        <div class="step-content">
          <h3>Prerequisites</h3>
          <p>You need a Linux server (Ubuntu 20.04+ recommended) with at least 2GB RAM and Podman or Docker installed. For on-prem LLMs, 8GB+ RAM is recommended.</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button><span class="comment"># Install Podman (recommended) or Docker</span><br>sudo apt install -y podman podman-compose</div>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">2</div>
        <div class="step-content">
          <h3>Clone & Install</h3>
          <p>Clone the repository and run the interactive installer. It sets up all 5 containers automatically.</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button>git clone https://gitlab.com/zenidev/zeniclaw.git /opt/zeniclaw<br>cd /opt/zeniclaw<br>bash install.sh</div>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">3</div>
        <div class="step-content">
          <h3>Connect WhatsApp</h3>
          <p>Open the dashboard, go to Settings > WhatsApp and scan the QR code. Auto-reconnect handles restarts.</p>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">4</div>
        <div class="step-content">
          <h3>Configure LLMs</h3>
          <p>In Settings, add your Anthropic API key (or Claude Max token) for cloud models. On-prem models can be downloaded directly from the UI &mdash; no CLI needed.</p>
          <div class="code-block"><button class="code-block-copy" onclick="copyCode(this)">Copy</button><span class="comment"># Default credentials</span><br>Email: admin@zeniclaw.io<br>Password: password</div>
        </div>
      </div>
      <div class="install-step">
        <div class="step-number">5</div>
        <div class="step-content">
          <h3>Start Chatting</h3>
          <p>Send a message to your linked WhatsApp number or use the built-in web chat. The RouterAgent auto-dispatches to the right agent.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section>
  <div class="section-inner" style="text-align: center;">
    <h2 class="section-title" style="margin-bottom: 1.5rem;">Ready to <span class="gradient-text">get started</span>?</h2>
    <p class="section-desc" style="margin: 0 auto 2rem;">Deploy your own AI command center in minutes. Cloud or on-prem, open source, self-hosted, completely free.</p>
    <div class="hero-actions" style="justify-content: center;">
      @auth
        <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to Dashboard</a>
      @else
        <a href="{{ route('login') }}" class="btn btn-primary">Sign In</a>
      @endauth
      <a href="https://gitlab.com/zenidev/zeniclaw" class="btn btn-secondary" target="_blank">View on GitLab</a>
      <a href="https://chat.whatsapp.com/G1ENranBGq63FYToMpcnYR" class="btn btn-whatsapp" target="_blank" rel="noopener">&#x1F4AC; Join Community</a>
    </div>
  </div>
</section>

<footer>
  <div class="footer-inner">
    <ul class="footer-links">
      <li><a href="https://gitlab.com/zenidev/zeniclaw" target="_blank">GitLab</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="#changelog">Changelog</a></li>
      <li><a href="#install">Install</a></li>
      <li><a href="https://chat.whatsapp.com/G1ENranBGq63FYToMpcnYR" target="_blank">Community</a></li>
      <li><a href="https://www.zenibiz.com" target="_blank">ZeniBiz</a></li>
      @auth
        <li><a href="{{ route('dashboard') }}">Dashboard</a></li>
      @else
        <li><a href="{{ route('login') }}">Sign In</a></li>
      @endauth
    </ul>
    <p class="footer-copy">&copy; {{ date('Y') }} ZeniClaw v2.26 by ZeniBiz &mdash; Self-hosted AI WhatsApp Platform</p>
  </div>
</footer>

<script>
function copyCode(btn) {
  const block = btn.parentElement;
  const text = block.innerText.replace('Copy', '').trim();
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => { if (e.isIntersecting) e.target.style.animationPlayState = 'running'; });
}, { threshold: 0.1 });
document.querySelectorAll('.fade-up').forEach(el => { el.style.animationPlayState = 'paused'; observer.observe(el); });
</script>
</body>
</html>
