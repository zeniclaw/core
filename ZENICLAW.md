# ZeniClaw — Spécification Produit

> Plateforme d'agents IA autonomes, on-premise, sécurisée et accessible à tous.

---

## 🎯 Vision

ZeniClaw est une alternative open-source et on-premise à OpenClaw.  
L'objectif : permettre à n'importe qui de déployer des agents IA autonomes chez soi ou sur son serveur, avec une interface claire, une sécurité maîtrisée, et zéro dépendance à un service cloud tiers.

**Différence clé vs OpenClaw :**
- Dashboard graphique (pas juste une CLI)
- Mémoire persistante indestructible (DB, pas contexte conversationnel)
- Sécurité first (secrets chiffrés, audit trail, RBAC)
- On-premise first (Docker Compose, 5 minutes pour démarrer)

---

## 🏗️ Stack Technique

| Couche       | Technologie                        |
|--------------|------------------------------------|
| Backend      | Laravel 11                         |
| Frontend     | Livewire + Alpine.js               |
| WebSockets   | Laravel Reverb                     |
| Queue        | Laravel Horizon (Redis)            |
| DB principale| PostgreSQL                         |
| Mémoire agent| SQLite par agent (ou Postgres)     |
| Secrets      | Vault intégré (chiffrement AES-256)|
| Déploiement  | Docker Compose (single file)       |

---

## 🧩 Architecture

### Agents

Chaque agent est une entité indépendante avec :
- **Identité** : nom, description, modèle LLM configuré
- **Mémoire** : base de données propre (SQLite ou table isolée Postgres)
- **Canal(aux)** : WhatsApp, Telegram, Web chat, Email
- **Permissions** : ce que l'agent peut faire (tools autorisés)
- **Crons** : tâches planifiées visibles dans le dashboard

```
Agent
├── identity.json       # nom, modèle, instructions système
├── memory/             # mémoire persistante (clé-valeur + vecteurs)
├── tools/              # tools autorisés (liste blanche)
├── channels/           # canaux configurés
└── crons/              # tâches planifiées
```

### Canaux (Channel Drivers)

Interface commune pour tous les canaux :

```php
interface ChannelDriver {
    public function send(string $to, string $message, array $options = []): void;
    public function receive(Request $request): InboundMessage;
    public function verifyWebhook(Request $request): bool;
}
```

**Canaux MVP :**
- 📱 WhatsApp (via WAHA / Baileys)
- 💬 Telegram (Bot API)
- 🌐 Web chat (WebSocket via Reverb)

**Canaux futurs :**
- 📧 Email (SMTP / Mailgun)
- 💼 Slack
- 🎮 Discord

Endpoint webhook universel :
```
POST /webhook/{channel}/{agentId}
```

---

## 🔔 Système de Rappels

**Problème résolu** : les rappels ne dépendent plus de la mémoire conversationnelle du LLM — ils sont stockés en DB et survivent à tout.

### Fonctionnalités

- **Création** : via chat ("rappelle-moi dans 2h de appeler Steph")
- **Stockage** : table `reminders` en DB avec statut (pending / sent / snoozed / done)
- **Déclenchement** : Laravel Scheduler → job → envoi multi-canal
- **Multi-canal** : WhatsApp + Email + Push simultanément si souhaité
- **Confirmation de lecture** : log "vu à 14h32"
- **Snooze** : "dans 1h", "demain matin"
- **Récurrence** : "tous les lundis", "chaque jour à 9h"
- **Vue dashboard** : liste de tous les rappels, statut, historique

### Modèle DB

```sql
reminders
  id, agent_id, user_id
  message, channel(s)
  scheduled_at, sent_at, seen_at
  recurrence_rule (cron expression)
  status (pending|sent|snoozed|done)
  created_at, updated_at
```

---

## 🔐 Sécurité

### Secrets Management
- Toutes les clés API (LLM, canaux, webhooks) chiffrées en AES-256 en DB
- Interface graphique pour ajouter/rotation des clés
- Jamais de clé en clair dans les logs
- Support futur : HashiCorp Vault, AWS Secrets Manager

### RBAC (Rôles)
| Rôle        | Accès                                      |
|-------------|--------------------------------------------|
| `superadmin`| Tout                                       |
| `admin`     | Gérer agents, canaux, utilisateurs         |
| `operator`  | Voir logs, déclencher crons manuellement   |
| `viewer`    | Dashboard lecture seule                    |

### Audit Trail
- Chaque action loguée : qui, quoi, quand, depuis quelle IP
- Logs immuables (append-only)
- Export CSV / JSON

### Isolation des agents
- Chaque agent tourne dans son propre contexte
- Un agent ne peut pas lire la mémoire d'un autre
- Tools accessibles = liste blanche explicite par agent

---

## 📊 Dashboard

### Vue principale
- Liste des agents (statut actif/inactif, dernier message, canal)
- Métriques globales : messages traités, coût LLM, erreurs

### Vue agent (détail)
- **Conversations** : historique en temps réel
- **Mémoire** : clés-valeurs lisibles et modifiables
- **Crons** : liste des tâches, prochaine exécution, historique runs
- **Rappels** : tous les rappels, statut, snooze
- **Logs** : logs filtrables par niveau (info/warn/error)
- **Secrets** : clés configurées (valeurs masquées)
- **Settings** : modèle LLM, instructions système, canaux

### Fonctionnalités UI
- Test de canal intégré ("envoyer un message test")
- Replay de webhook (pour déboguer)
- Déclencher un cron manuellement
- Éditer la mémoire agent directement

---

## 🚀 Déploiement (MVP)

### Docker Compose (single file)

```yaml
services:
  app:
    image: zenibiz/zeniclaw:latest
    ports: ["8080:80"]
    environment:
      - DB_CONNECTION=pgsql
      - REDIS_HOST=redis
    depends_on: [db, redis]

  db:
    image: postgres:16
    volumes: [pgdata:/var/lib/postgresql/data]

  redis:
    image: redis:alpine

volumes:
  pgdata:
```

```bash
curl -sSL https://zeniclaw.io/install.sh | bash
# → télécharge docker-compose.yml, configure .env, démarre
```

### Premier démarrage
1. `docker compose up -d`
2. Ouvrir `http://localhost:8080`
3. Wizard de configuration : admin account, premier canal, premier agent
4. C'est parti

---

## 📋 Roadmap MVP

### Phase 1 — Fondations
- [ ] Projet Laravel + Docker Compose
- [ ] Auth (admin + multi-user)
- [ ] CRUD Agents (nom, instructions, modèle LLM)
- [ ] Mémoire persistante par agent (clé-valeur)
- [ ] Dashboard basique

### Phase 2 — Canaux
- [ ] Driver WhatsApp (WAHA)
- [ ] Driver Telegram
- [ ] Web chat (Reverb WebSocket)
- [ ] Webhook universel + routing

### Phase 3 — Autonomie
- [ ] Système de crons (création via chat + dashboard)
- [ ] Système de rappels (DB-backed, multi-canal)
- [ ] Queue workers (Horizon)
- [ ] Logs temps réel dans le dashboard

### Phase 4 — Sécurité
- [ ] Secrets chiffrés (vault intégré)
- [ ] RBAC (rôles)
- [ ] Audit trail
- [ ] 2FA

### Phase 5 — Polish & Open Source
- [ ] Wizard de setup (premier lancement)
- [ ] Documentation
- [ ] Tests automatisés
- [ ] Publication open-source

---

## 💡 Inspirations

- **OpenClaw** : modèle d'agents + canaux + crons (mais CLI-centric et pas open-source)
- **n8n** : workflow automation on-premise, excellent UX dashboard
- **Coolify** : self-hosted, Docker, wizard de setup
- **Flowise** : agents visuels, mais trop complexe pour les non-devs

---

*ZeniClaw — Construit par Zenibiz. Pour tout le monde.*
