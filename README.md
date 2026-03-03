# 🦅 ZeniClaw

**ZeniClaw** is an open-source autonomous AI agent platform built with Laravel 11. Create, manage, and monitor AI agents that can send reminders, answer messages, and execute tasks — all via a clean web UI.

---

## ✨ Features

### Core
- 🤖 **Multi-agent management** — Create unlimited agents with custom system prompts
- 🧠 **Claude Max support** — Use your Anthropic Claude Max subscription (claude-sonnet-4-5, claude-opus-4-5, claude-haiku-4-5)
- 🟢 **OpenAI support** — GPT-4o, GPT-4o Mini
- 📱 **WhatsApp integration** — Via WAHA (WhatsApp HTTP API, open-source)
- ⏰ **Smart reminders** — Schedule messages with iCal RRULE recurrence
- 📋 **Agent logs** — Info/warn/error with auto-compaction (>1000 entries archived)
- 🧠 **Two-layer memory** — Daily notes (7 days) + long-term memory per agent
- 💬 **Session tracking** — Track conversations per agent/channel/peer
- 🔑 **API tokens** — Generate tokens for webhook authentication
- 🔄 **Self-update** — Pull latest version from GitLab via UI
- 🏥 **Health monitoring** — `/health` endpoint + admin health dashboard
- 🛡️ **Role-based access** — superadmin / admin / operator / viewer
- 🔒 **Data sandboxing** — All data strictly scoped to authenticated user
- 🧪 **Self-test suite** — Feature tests covering auth, agents, reminders, logs, webhooks

### Projects & SubAgents (v2.0.0)
- 📂 **Projects** — Create projects linked to GitLab repos, manage approval workflow
- 🤖 **SubAgents** — Autonomous Claude Code execution: clone repo, apply changes, commit, push, create & auto-merge MR
- 📱 **WhatsApp task detection** — Send a GitLab URL or a task description via WhatsApp, ZeniClaw detects and dispatches automatically
- 💬 **Conversations view** — Browse all WhatsApp conversations with message history
- 👥 **Contacts view** — See all WhatsApp contacts who interacted with ZeniClaw
- 📢 **WhatsApp group notifications** — Select groups to notify when a project is created
- 🏷️ **[PROJECT] prefix** — All project-related messages are prefixed with `[ProjectName]` for clarity
- 🔗 **GitLab integration** — Search repos, auto-create branches, merge requests, and auto-merge
- 🧠 **Conversation memory** — Per-contact memory with AI-generated summaries
- ⏱️ **SubAgent timeout & kill** — Configurable timeout per SubAgent, kill button in dashboard

---

## 🚀 Quick Start (4 commands)

```bash
git clone https://gitlab.com/zenibiz/zeniclaw.git
cd zeniclaw
cp .env.example .env
sudo docker-compose up -d
```

Then open **http://localhost:8080** 🎉

---

## 🔑 Default Credentials

| Field    | Value                  |
|----------|------------------------|
| Email    | `admin@zeniclaw.io`    |
| Password | `password`             |
| Role     | `superadmin`           |

> ⚠️ Change the password immediately after first login!

---

## 🐳 Docker Services

| Service | Description             | Port     |
|---------|-------------------------|----------|
| `app`   | Laravel (nginx+php-fpm) | 8080     |
| `db`    | PostgreSQL 16           | internal |
| `redis` | Redis 7                 | internal |
| `waha`  | WAHA WhatsApp API       | 3000     |

---

## 🧪 Test Commands

```bash
# Run migrations + seed
sudo docker-compose exec app php artisan migrate --seed

# Health check
sudo docker-compose exec app php artisan zeniclaw:health

# Process pending reminders manually
sudo docker-compose exec app php artisan reminders:process

# Compact logs (archive old logs)
sudo docker-compose exec app php artisan zeniclaw:compact-logs

# Run self-test suite (needs sqlite or test DB)
sudo docker-compose exec app php artisan test --filter ZeniClawSelfTest
```

---

## 🔄 Self-Update

Via the UI: go to **Settings → Mises à jour** (admin only)

Or via CLI:
```bash
sudo docker-compose exec app php artisan zeniclaw:update
```

---

## 📱 WhatsApp Setup

1. Go to **Settings → Canaux**
2. Click **"Connecter WhatsApp"**
3. Scan the QR code with your WhatsApp app
4. Done — your agents can now send/receive WhatsApp messages

WAHA runs at `http://localhost:3000` (API dashboard available there).

---

## 🧠 LLM Configuration

Go to **Settings → LLM Providers** and enter your API keys:

### Claude Max (Anthropic)
- Model options: `claude-sonnet-4-5` (default), `claude-opus-4-5`, `claude-haiku-4-5`
- Get your key at: https://console.anthropic.com
- 💡 **Claude Max subscribers**: Your API key is included in the Claude Max subscription — no separate billing needed.

### OpenAI
- Model options: `gpt-4o`, `gpt-4o-mini`
- Get your key at: https://platform.openai.com

---

## 🔗 GitLab Configuration

Go to **Settings → GitLab** and enter:
- **GitLab Access Token** — Personal access token with `api` scope (for repo search, clone, MR creation)

This enables:
- Searching GitLab repos when creating projects
- SubAgents cloning repos and pushing changes
- Auto-creation and auto-merge of Merge Requests

---

## 📂 Projects

Projects link a **GitLab repository** to ZeniClaw. Two ways to create:

### Via Dashboard
1. Go to **Projects → Nouveau projet**
2. Search and select a GitLab repo
3. Add description (optional)
4. Select authorized WhatsApp contacts (optional) — they can send tasks directly
5. Select WhatsApp groups to notify (optional)
6. Click **Créer** — project is auto-approved

### Via WhatsApp
1. Send a GitLab URL to ZeniClaw on WhatsApp
2. ZeniClaw detects the URL and creates a pending project
3. An admin approves/rejects from the dashboard
4. Once approved, SubAgent is dispatched automatically

### Task Detection
ZeniClaw uses Claude Haiku to classify incoming WhatsApp messages:
- **TASK** → dispatched to the matching project's SubAgent
- **CHAT** → handled as casual conversation

Priority for project matching:
1. Project name mentioned in message
2. Phone in project's `allowed_phones`
3. User's last active project

### Message Prefix
All project-related messages (progress, completion, errors) are prefixed with `[ProjectName]`:
```
[my-app] C'est parti ! Je bosse dessus.
[my-app] Repo recupere. ZeniClaw AI analyse le code...
[my-app] C'est fait ! Les modifications ont ete mergees.
```

---

## 🤖 SubAgents

SubAgents are autonomous workers that execute tasks on a project's GitLab repo.

### Workflow
1. **Clone** — Git clone with depth 50
2. **Branch** — Reuse existing branch from previous SubAgent or create new one (`zeniclaw/subagent-{id}`)
3. **Execute** — Run Claude Code CLI with the task description (tries Opus first, falls back to Sonnet)
4. **Commit & Push** — Stage all changes, commit, push to branch
5. **Merge Request** — Create MR (or add commit to existing MR), then auto-merge

### Features
- **Configurable timeout** — Default 10 minutes, adjustable per SubAgent and globally
- **Kill button** — Stop a running SubAgent from the dashboard
- **Real-time logs** — Stream Claude Code output in the SubAgent detail page
- **Context continuity** — Previous task descriptions are injected as context for new tasks
- **Model fallback** — Opus → Sonnet if API is overloaded
- **API call counter** — Track how many Claude API calls each SubAgent made

### Dashboard
- **List view** (`/subagents`) — All SubAgents with status, project, duration
- **Detail view** (`/subagents/{id}`) — Full execution log, task description, commit hash, MR link

---

## 🏥 Health Check

**API endpoint** (no auth required):
```bash
curl http://localhost:8080/health
# → {"status":"ok","version":"2.0.0","db":{"ok":true,"ms":1.2},"redis":{"ok":true,"ms":0.3},"timestamp":"..."}
```

**Admin dashboard**: http://localhost:8080/admin/health

---

## 🗂️ Project Structure

```
app/
  Http/Controllers/           # All controllers
  Http/Controllers/Admin/     # UpdateController, HealthDashboardController
  Models/                     # Agent, AgentLog, AgentMemory, AgentSession, Project, SubAgent
  Jobs/                       # RunSubAgentJob (autonomous task execution)
  Services/                   # AnthropicClient, ConversationMemoryService
  Console/Commands/           # zeniclaw:health, zeniclaw:update, zeniclaw:compact-logs
  Policies/                   # AgentPolicy (user scoping)
database/
  migrations/                 # All table definitions
  seeders/                    # Admin user + 3 demo agents
docker/
  nginx/default.conf
  php/php.ini
  entrypoint.sh               # Runs health check, migrations, queue worker, starts services
resources/views/
  agents/                     # Agent CRUD views
  contacts/                   # Contact list
  conversations/              # Conversation list + detail
  projects/                   # Project list, create, detail
  subagents/                  # SubAgent list + detail with logs
  settings/                   # Settings (LLM, GitLab, WhatsApp, tokens)
  layouts/                    # App layout with sidebar navigation
tests/Feature/
  ZeniClawSelfTest.php        # 20+ feature tests
```

---

## 🔒 Security Notes

- All database queries are scoped to `auth()->user()` via policies and query scoping
- API keys are encrypted at rest using Laravel's `Crypt` facade
- Agent secrets are encrypted using `Crypt::encryptString()`
- API tokens are stored as SHA-256 hashes (never in plain text)
- Admin routes (`/admin/*`) require `superadmin` role middleware
- Public endpoints: `/health`, `/webhook/whatsapp/{agent}` (webhook auth via API token)

---

## 📦 Tech Stack

| Component    | Technology                    |
|-------------|-------------------------------|
| Framework    | Laravel 11                    |
| Auth         | Laravel Breeze (Blade)        |
| Frontend     | Tailwind CSS + Alpine.js      |
| Realtime UI  | Livewire 3                    |
| Database     | PostgreSQL 16                 |
| Cache/Queue  | Redis 7                       |
| WhatsApp     | WAHA (devlikeapro/waha)       |
| Container    | Docker + nginx + php-fpm 8.3  |

---

## 📝 Changelog

### v2.0.0 (2026-03-03)
- **Projects system** — Create projects linked to GitLab repos with approval workflow
- **SubAgents** — Autonomous Claude Code execution (clone, modify, commit, push, MR, auto-merge)
- **WhatsApp task detection** — Claude Haiku classifies messages as TASK or CHAT
- **Conversations view** — Browse WhatsApp conversations with message history
- **Contacts view** — All WhatsApp contacts who interacted with ZeniClaw
- **WhatsApp group notifications** — Select groups to notify on project creation
- **[PROJECT] prefix** — All project messages prefixed with `[ProjectName]`
- **GitLab integration** — Repo search, branch management, MR creation & auto-merge
- **AnthropicClient service** — Centralized Claude API client
- **ConversationMemoryService** — Per-contact memory with AI-generated summaries
- **SubAgent timeout & kill** — Configurable timeout, kill from dashboard
- **Model fallback** — Opus → Sonnet if API overloaded
- **Settings: GitLab** — Configure GitLab access token from UI
- **Docker improvements** — Queue worker in entrypoint, improved health checks

### v1.0.0 (2026-03-01)
- Initial MVP release
- Multi-agent management with CRUD
- Laravel Breeze authentication
- WhatsApp channel via WAHA
- Claude Max + OpenAI model support
- Reminders with recurrence rules
- Agent logs with compaction
- Two-layer memory system (daily + longterm)
- Session tracking per channel/peer
- API token management
- Self-update system via GitLab API
- Health monitoring dashboard
- Full feature test suite

---

Made with ❤️ by the ZeniClaw team.
