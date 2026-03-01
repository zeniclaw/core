# 🦅 ZeniClaw

**ZeniClaw** is an open-source autonomous AI agent platform built with Laravel 11. Create, manage, and monitor AI agents that can send reminders, answer messages, and execute tasks — all via a clean web UI.

---

## ✨ Features

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

## 🏥 Health Check

**API endpoint** (no auth required):
```bash
curl http://localhost:8080/health
# → {"status":"ok","version":"1.0.0","db":{"ok":true,"ms":1.2},"redis":{"ok":true,"ms":0.3},"timestamp":"..."}
```

**Admin dashboard**: http://localhost:8080/admin/health

---

## 🗂️ Project Structure

```
app/
  Http/Controllers/       # All controllers
  Http/Controllers/Admin/ # UpdateController, HealthDashboardController
  Models/                 # Agent, AgentLog, AgentMemory, AgentSession, etc.
  Console/Commands/       # zeniclaw:health, zeniclaw:update, zeniclaw:compact-logs
  Policies/               # AgentPolicy (user scoping)
database/
  migrations/             # All table definitions
  seeders/                # Admin user + 3 demo agents
docker/
  nginx/default.conf
  php/php.ini
  entrypoint.sh           # Runs health check, migrations, starts services
resources/views/          # All Blade templates
tests/Feature/
  ZeniClawSelfTest.php    # 20+ feature tests
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
