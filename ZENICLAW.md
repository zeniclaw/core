# ZeniClaw — Product Specification

> On-premise autonomous AI agent platform. Secure, self-hosted, built for everyone.

---

## Vision

ZeniClaw is an open-source, on-premise alternative to OpenClaw.  
The goal: let anyone deploy autonomous AI agents on their own server, with a clear UI, strong security, and zero dependency on third-party cloud services.

**Key differences vs OpenClaw:**
- Graphical dashboard (not just a CLI)
- Indestructible persistent memory (DB-backed, not conversational context)
- Security first (encrypted secrets, audit trail, RBAC)
- On-premise first (Docker Compose, 5 minutes to start)
- Full self-test suite — everything verifiable out of the box
- Agent sandbox isolation (no cross-user data leaks)

---

## Tech Stack

| Layer        | Technology                          |
|--------------|-------------------------------------|
| Backend      | Laravel 11                          |
| Frontend     | Livewire 3 + Alpine.js + Tailwind   |
| WebSockets   | Laravel Reverb                      |
| Queue        | Laravel Horizon (Redis)             |
| Database     | PostgreSQL                          |
| Agent memory | `agent_memory` table per agent      |
| Secrets      | AES-256 encrypted in DB             |
| Deployment   | Docker Compose (single file)        |

---

## Architecture

### Agents

Each agent is an independent entity with:
- **Identity**: name, description, system prompt, LLM model
- **Memory**: two-layer system (daily notes + long-term curated facts)
- **Sessions**: isolated per channel + peer (no cross-user context leaks)
- **Channels**: WhatsApp, Telegram, Web chat, Email
- **Permissions**: explicit tool allowlist
- **Crons**: scheduled tasks visible in the dashboard
- **Sandbox**: fully isolated — agents cannot access each other's data

### Channel Drivers

Common interface for all channels:

```php
interface ChannelDriver {
    public function send(string $to, string $message, array $options = []): void;
    public function receive(Request $request): InboundMessage;
    public function verifyWebhook(Request $request): bool;
}
```

**MVP channels:**
- 📱 WhatsApp (via WAHA — QR code pairing)
- 💬 Telegram (Bot API)
- 🌐 Web chat (WebSocket via Reverb)

**Future channels:**
- 📧 Email (SMTP / Mailgun)
- 💼 Slack
- 🎮 Discord

Universal webhook endpoint:
```
POST /webhook/{channel}/{agentId}
```
Authenticated via Bearer API token.

### WhatsApp via WAHA

WAHA (WhatsApp HTTP API) runs as a Docker service. No Business account required — works with a regular WhatsApp account.

**Flow:**
1. User goes to Settings → Channels → WhatsApp
2. ZeniClaw starts a WAHA session via REST API
3. QR code displayed in the UI (refreshed every 5s via Alpine.js polling)
4. User scans with their phone
5. ✅ Connected — inbound messages arrive via webhook

WAHA service in docker-compose:
```yaml
waha:
  image: devlikeapro/waha
  volumes: [waha_data:/app/.sessions]
```

---

## Database Schema

### Core tables

```sql
users          — id, name, email, password, role(superadmin|admin|operator|viewer)
agents         — id, user_id, name, description, system_prompt, model, status(active|inactive)
agent_secrets  — id, agent_id, key_name, encrypted_value
agent_logs     — id, agent_id, level(info|warn|error), message, context(json)
agent_logs_archive — same schema, old logs moved here on compaction
agent_memory   — id, agent_id, type(daily|longterm), date(nullable), content(text)
agent_sessions — id, agent_id, session_key, channel, peer_id, last_message_at, message_count
reminders      — id, agent_id, user_id, message, channel, scheduled_at, sent_at, recurrence_rule, status(pending|sent|snoozed|done)
app_settings   — id, key, encrypted_value (global LLM API keys etc.)
api_tokens     — id, user_id, name, token_hash, last_used_at
```

---

## LLM Providers

Model selector for each agent:

| Model                  | Provider  | Notes                                     |
|------------------------|-----------|-------------------------------------------|
| claude-sonnet-4-5 ⭐   | Anthropic | Default. Works with Claude Max subscription |
| claude-opus-4-5        | Anthropic | Most powerful                             |
| claude-haiku-4-5       | Anthropic | Fastest / cheapest                        |
| gpt-4o                 | OpenAI    |                                           |
| gpt-4o-mini            | OpenAI    | Budget option                             |

> **Claude Max subscribers**: use your Anthropic API key directly — it's included in your subscription.

API keys stored encrypted in `app_settings`. Configurable in Settings → LLM Providers.

---

## Reminder System

**Problem solved**: reminders are stored in DB — they survive restarts, context compaction, everything. No more "forgotten reminders" from conversational memory loss.

### Features
- **Creation**: via chat ("remind me in 2h to call Steph") or dashboard form
- **Storage**: `reminders` table with status tracking
- **Trigger**: Laravel Scheduler → job → multi-channel delivery
- **Multi-channel**: WhatsApp + Email + Push simultaneously
- **Read confirmation**: logged when seen
- **Snooze**: "in 1h", "tomorrow morning"
- **Recurrence**: "every Monday", "daily at 9am"
- **Dashboard view**: full list with status, history

---

## Memory System (two-layer, inspired by OpenClaw)

Each agent has persistent memory that survives context compaction:

| Layer       | Storage                | Purpose                              |
|-------------|------------------------|--------------------------------------|
| Daily notes | `agent_memory` (daily) | Raw logs of what happened today      |
| Long-term   | `agent_memory` (longterm) | Curated facts, decisions, preferences |

Visible in agent detail page → "Memory" tab:
- Daily notes (last 7 days)
- Long-term facts
- "Clear memory" button (with confirmation)

---

## Session Management (inspired by OpenClaw dmScope)

**Lesson from OpenClaw**: without session isolation, users can leak each other's context. ZeniClaw isolates by default.

Session key format: `agent:{agentId}:{channel}:dm:{peerId}` or `agent:{agentId}:{channel}:group:{chatId}`

Stored in `agent_sessions` table. Visible in agent detail page:
- Channel icon (📱 WhatsApp, 💬 Telegram, 🌐 Web)
- Last activity + message count
- "Reset session" button (clears context)

---

## Agent Sandbox Isolation

**Critical security feature**: every agent and user is fully isolated.

- All queries scoped to `auth()->user()` — never cross-user data access
- `AgentPolicy` enforces ownership on every CRUD operation
- Agents cannot read each other's memory, logs, or sessions
- API tokens scoped to the owning user

---

## Self-Update System

Settings → Updates (superadmin only):

- Shows current version (from `storage/app/version.txt`) vs latest GitLab tag
- Displays last 5 commits as changelog
- One-click update: runs `git pull` + `composer install` + migrations + cache clear
- Live log output during update (Alpine.js polling)
- Version shown in sidebar footer: "ZeniClaw v1.0.0"

Artisan command: `php artisan zeniclaw:update`

GitLab API (public, no token needed):
- Tags: `GET https://gitlab.com/api/v4/projects/zenibiz%2Fzeniclaw/repository/tags`
- Commits: `GET https://gitlab.com/api/v4/projects/zenibiz%2Fzeniclaw/repository/commits?ref_name=main&per_page=5`

---

## Health & Monitoring

### GET /health (no auth required)
```json
{ "status": "ok", "version": "1.0.0", "db": "ok", "redis": "ok", "timestamp": "..." }
```

### php artisan zeniclaw:health
Console command checking all services. Run automatically by Docker entrypoint before startup — exits with error code if critical services are down.

### Dashboard health card
Real-time status of: DB ✅, Redis ✅, WAHA ✅/❌, Scheduler ✅/❌

### Admin health page (/admin/health)
- All health checks with response times
- Recent errors from agent_logs (last 24h)
- Queue status (pending jobs)

---

## Log Compaction

Inspired by OpenClaw's session maintenance:

- When an agent exceeds 1,000 log entries → automatically archive logs older than 30 days to `agent_logs_archive`
- Command: `php artisan zeniclaw:compact-logs`
- Scheduled daily via Laravel Scheduler

---

## API Tokens

For external access (webhooks, integrations):

- Generated in Settings → API Tokens
- Shown once, stored as bcrypt hash
- Authenticates webhook endpoints: `Authorization: Bearer <token>`
- Last used timestamp tracked

---

## Security

### Secrets Management
- All API keys (LLM, channels, webhooks) encrypted AES-256 in DB
- Never stored in plain text in logs
- Future: HashiCorp Vault, AWS Secrets Manager integration

### RBAC
| Role         | Access                                        |
|--------------|-----------------------------------------------|
| `superadmin` | Everything (including update + health pages)  |
| `admin`      | Manage agents, channels, users                |
| `operator`   | View logs, trigger crons manually             |
| `viewer`     | Read-only dashboard                           |

### Audit Trail
- Every action logged: who, what, when, from which IP
- Append-only logs
- CSV / JSON export

### Agent Isolation
- Each agent runs in its own data scope
- Tool allowlist per agent
- No agent can read another agent's memory or sessions

---

## Dashboard UI

### Main view
- Agent list (status, last message, channel)
- Global metrics: messages processed, LLM cost, errors
- System health card

### Agent detail
- **Conversations**: real-time history
- **Memory**: daily notes + long-term facts (editable)
- **Sessions**: active sessions per channel, reset button
- **Crons**: tasks, next run, run history
- **Reminders**: list, status, snooze
- **Logs**: filterable by level (info/warn/error)
- **Secrets**: configured keys (values masked)
- **Settings**: LLM model, system prompt, channels

### UI Features
- Integrated channel test ("send a test message")
- Webhook replay (for debugging)
- Manual cron trigger
- Edit agent memory directly

---

## Pre-configured Demo Agents (seeded)

| Agent          | Model              | Purpose                              |
|----------------|--------------------|--------------------------------------|
| 🤖 Coding Agent | claude-sonnet-4-5  | Dev expert: Laravel, PHP, JS, Docker |
| 📊 Analytics Agent | claude-haiku-4-5 | Data analysis, reports              |
| 🔔 Notifier     | gpt-4o-mini        | Reminders and alerts                |

---

## Deployment

### Docker Compose

Services:
- `app` — PHP 8.3-fpm + Laravel
- `nginx` — reverse proxy (port 8080)
- `db` — PostgreSQL 16
- `redis` — Redis Alpine
- `waha` — WhatsApp HTTP API

```bash
git clone https://gitlab.com/zenibiz/zeniclaw.git
cd zeniclaw
cp .env.example .env
docker-compose up -d
```

Open: `http://localhost:8080`  
Login: `admin@zeniclaw.io` / `password`

### First setup wizard
1. `docker-compose up -d`
2. Open `http://localhost:8080`
3. Login with demo credentials
4. Go to Settings → LLM Providers → add your Anthropic or OpenAI API key
5. Go to Settings → Channels → WhatsApp → scan QR code
6. Create your first agent

---

## Testing

After `docker-compose up -d`, run:

```bash
# Full test suite
docker-compose exec app php artisan test

# Health check
docker-compose exec app php artisan zeniclaw:health

# List all routes
docker-compose exec app php artisan route:list

# Manually trigger log compaction
docker-compose exec app php artisan zeniclaw:compact-logs

# Run self-update
docker-compose exec app php artisan zeniclaw:update
```

Expected: all green ✅

---

## Roadmap

### Phase 1 — Foundations ✅ (MVP)
- [x] Laravel 11 + Docker Compose
- [x] Auth (multi-user + RBAC)
- [x] Agent CRUD with sandbox isolation
- [x] Two-layer memory system
- [x] Session management
- [x] Dashboard with health monitoring
- [x] Self-test suite

### Phase 2 — Channels ✅ (MVP)
- [x] WhatsApp via WAHA (QR code)
- [x] Telegram (Bot API)
- [x] Universal webhook + routing
- [x] API tokens

### Phase 3 — Autonomy ✅ (MVP)
- [x] Cron system (UI + scheduler)
- [x] Reminder system (DB-backed, multi-channel)
- [x] Queue workers
- [x] Real-time logs in dashboard
- [x] Log compaction

### Phase 4 — Security ✅ (MVP)
- [x] Encrypted secrets (built-in vault)
- [x] RBAC (roles)
- [x] Audit trail
- [x] Agent sandbox isolation

### Phase 5 — Self-Update ✅ (MVP)
- [x] Self-update via GitLab API
- [x] Version tracking
- [x] One-click update UI

### Phase 6 — Future
- [ ] Telegram channel driver
- [ ] Web chat (Reverb WebSocket)
- [ ] 2FA
- [ ] Multi-agent orchestration
- [ ] Visual workflow builder
- [ ] Public marketplace for agents
- [ ] Open-source release

---

## Inspirations

- **OpenClaw**: agent + channel + cron model (CLI-centric, not open-source)
- **n8n**: workflow automation on-premise, excellent dashboard UX
- **Coolify**: self-hosted, Docker, setup wizard
- **Flowise**: visual agents, but too complex for non-devs

---

*ZeniClaw — Built by Zenibiz. For everyone.*
