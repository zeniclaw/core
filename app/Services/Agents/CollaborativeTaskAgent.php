<?php

namespace App\Services\Agents;

use App\Models\CollaborativeVote;
use App\Services\AgentContext;

class CollaborativeTaskAgent extends BaseAgent
{
    public function name(): string
    {
        return 'collaborative_task';
    }

    public function description(): string
    {
        return 'Taches partagees avec votes et consensus en equipe. Creer des propositions, voter, decider en groupe via WhatsApp';
    }

    public function keywords(): array
    {
        return [
            'vote', 'voter', 'approve', 'approuver', 'decide', 'decision',
            'consensus', 'proposition', 'proposer', 'equipe', 'team',
            'avis', 'sondage', 'poll', 'quorum', 'valider', 'rejeter',
            '/vote', '/approve', '/decide', 'votes en cours',
            'annuler proposition', '/annuler', '/stats', 'stats votes',
            'participation', 'changer vote', 'modifier vote',
            'mes propositions', '/mes', '/rappel', 'rappel votes',
            'rappeler votes', 'propositions creees',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if (preg_match('/^\/(?:vote|approve|decide|annuler|stats|mes|rappel)\b/iu', $body)) {
            return true;
        }
        if (preg_match('/\b(vote|voter|approve|consensus|sondage|poll|proposer|soumettre|annuler\s+proposition|stats\s+votes?|participation\s+votes?|mes\s+propositions?|rappel(?:er)?\s+votes?)\b/iu', $body)) {
            return true;
        }
        if (preg_match('/^(👍|👎|❓)\s*\d+\s*$/u', $body)) {
            return true;
        }
        if (preg_match('/\b(changer\s+(?:mon\s+)?vote|modifier\s+(?:mon\s+)?vote)\b/iu', $body)) {
            return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body  = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // /vote <id> — show votes for a specific proposal
        if (preg_match('/^\/vote\s+(\d+)\s*$/iu', $body, $m)) {
            return $this->handleShowVote($context, (int) $m[1]);
        }

        // /vote — list all pending votes
        if (preg_match('/^\/vote\s*$/iu', $body)) {
            return $this->handleListVotes($context);
        }

        // /approve <quorum>% <description> — create a proposal with custom quorum
        if (preg_match('/^\/approve\s+(\d{1,3})%?\s+(.{5,})$/iu', $body, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 100) {
            return $this->handleCreateProposal($context, trim($m[2]), (int) $m[1]);
        }

        // /approve <description> — create a new vote proposal
        if (preg_match('/^\/approve\s+(.+)$/iu', $body, $m)) {
            return $this->handleCreateProposal($context, trim($m[1]));
        }

        // /annuler <id> — cancel a proposal
        if (preg_match('/^\/annuler\s+(\d+)\s*$/iu', $body, $m)) {
            return $this->handleCancelProposal($context, (int) $m[1]);
        }

        // /decide <action> on <id> — force a decision
        if (preg_match('/^\/decide\s+(approve|reject|approuver|rejeter|valider|annuler)\s+(\d+)\s*$/iu', $body, $m)) {
            $action    = mb_strtolower($m[1]);
            $isApprove = in_array($action, ['approve', 'approuver', 'valider']);
            return $this->handleForceDecision($context, (int) $m[2], $isApprove);
        }

        // /stats — team voting statistics
        if (preg_match('/^\/stats\s*$/iu', $body) || preg_match('/\b(stats\s+votes?|participation\s+votes?|statistiques\s+votes?)\b/iu', $lower)) {
            return $this->handleTeamStats($context);
        }

        // /mes — show my proposals
        if (preg_match('/^\/mes\s*$/iu', $body) || preg_match('/\b(mes\s+propositions?|propositions?\s+cre(?:e|ee)s?)\b/iu', $lower)) {
            return $this->handleMesPropositions($context);
        }

        // /rappel — remind the group of pending votes
        if (preg_match('/^\/rappel\s*$/iu', $body) || preg_match('/\b(rappel(?:er)?\s+votes?|rappel\s+propositions?)\b/iu', $lower)) {
            return $this->handleRappelVotes($context);
        }

        // Natural language: "propose ...", "proposer ...", "soumettre ..."
        if (preg_match('/\b(propos(?:e|er)|soumettre|submit|suggest)\s+(.+)/iu', $lower, $m)) {
            return $this->handleCreateProposal($context, trim($m[2]));
        }

        // Natural language: "annuler proposition <id>" or "annuler ma proposition <id>"
        if (preg_match('/\bannuler\s+(?:ma\s+)?proposition\s+(\d+)\s*$/iu', $lower, $m)) {
            return $this->handleCancelProposal($context, (int) $m[1]);
        }

        // "voter pour/contre <id>" or reaction-style "👍 <id>" "👎 <id>"
        if (preg_match('/\b(?:voter?\s+(?:pour|for))\s+(\d+)\s*$/iu', $lower, $m)) {
            return $this->handleCastVote($context, (int) $m[1], '👍');
        }
        if (preg_match('/\b(?:voter?\s+(?:contre|against))\s+(\d+)\s*$/iu', $lower, $m)) {
            return $this->handleCastVote($context, (int) $m[1], '👎');
        }
        if (preg_match('/^(👍|👎|❓)\s*(\d+)\s*$/u', $body, $m)) {
            return $this->handleCastVote($context, (int) $m[2], $m[1]);
        }

        // "changer vote <id> 👍/👎/❓" — change an existing vote
        if (preg_match('/\b(?:changer|modifier)\s+(?:mon\s+)?vote\s+(\d+)\s*(👍|👎|❓)?\s*$/iu', $body, $m)) {
            $emoji = $m[2] ?? null;
            return $this->handleChangeVote($context, (int) $m[1], $emoji);
        }

        // "votes en cours", "pending votes", "mes votes"
        if (preg_match('/\b(votes?\s+en\s+cours|pending\s+votes|mes\s+votes|list\s+votes)\b/iu', $lower)) {
            return $this->handleListVotes($context);
        }

        // "historique votes", "vote history"
        if (preg_match('/\b(historique\s+votes?|vote\s+history|resultats?\s+votes?)\b/iu', $lower)) {
            return $this->handleVoteHistory($context);
        }

        // "set quorum <percent> pour #<id>" or "quorum <percent>"
        if (preg_match('/\b(?:set\s+)?quorum\s+(\d+)\s*%?\s+(?:pour|for)\s+#?(\d+)\s*$/iu', $body, $m)) {
            return $this->handleSetQuorum($context, (int) $m[1], (int) $m[2]);
        }
        if (preg_match('/\b(?:set\s+)?quorum\s+(\d+)\s*%?\s*$/iu', $body, $m)) {
            return $this->handleSetQuorum($context, (int) $m[1]);
        }

        return $this->handleHelp($context);
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private function handleCreateProposal(AgentContext $context, string $description, int $quorum = 60): AgentResult
    {
        $description = trim($description);

        if (mb_strlen($description) < 5) {
            $reply  = "⚠️ La description est trop courte (minimum 5 caracteres).\n\n";
            $reply .= "_Exemple : /approve Deployer la v2.0 en production_\n";
            $reply .= "_Avec quorum custom : /approve 75% Deployer la v2.0_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if (mb_strlen($description) > 300) {
            $description = mb_substr($description, 0, 297) . '...';
        }

        $quorum  = max(1, min(100, $quorum));
        $groupId = $context->from;

        $vote = CollaborativeVote::create([
            'message_group_id' => $groupId,
            'task_description' => $description,
            'vote_quorum'      => $quorum,
            'created_by'       => $context->senderName ?? $context->from,
            'status'           => 'pending',
            'votes'            => [],
        ]);

        $deadline = now()->addHours(24)->format('d/m H:i');

        $reply  = "🗳️ *Nouvelle proposition #" . $vote->id . "*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📋 {$description}\n\n";
        $reply .= "👤 Proposee par : *" . ($context->senderName ?? $context->from) . "*\n";
        $reply .= "📊 Quorum requis : *{$vote->vote_quorum}%* des votes 👍\n";
        $reply .= "⏰ Expire le : *{$deadline}*\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "*Comment voter :*\n";
        $reply .= "  👍 {$vote->id} — Approuver\n";
        $reply .= "  👎 {$vote->id} — Rejeter\n";
        $reply .= "  ❓ {$vote->id} — Abstention\n\n";
        $reply .= "_Ou : voter pour {$vote->id} / voter contre {$vote->id}_\n";
        $reply .= "_Annuler votre proposition : /annuler {$vote->id}_\n";
        $reply .= "_Rappeler l'equipe : /rappel_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Proposal created', ['vote_id' => $vote->id, 'description' => $description, 'quorum' => $quorum]);

        return AgentResult::reply($reply, ['action' => 'proposal_created', 'vote_id' => $vote->id]);
    }

    private function handleCastVote(AgentContext $context, int $voteId, string $emoji): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.\n_Tapez /vote pour voir les propositions en cours._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $statusLabel = match (true) {
                $vote->status === 'approved' => 'approuvee ✅',
                $this->isCancelled($vote)    => 'annulee 🚫',
                default                      => 'rejetee ❌',
            };
            $reply  = "⚠️ La proposition #{$voteId} est deja *{$statusLabel}*.\n";
            $reply .= "_Impossible de voter sur une proposition cloturee._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($this->isExpired($vote)) {
            $vote->reject();
            $reply  = "⏰ La proposition #{$voteId} a expire (plus de 24h).\n";
            $reply .= "_Elle a ete automatiquement cloturee._\n\n";
            $reply .= "_Creez une nouvelle proposition : /approve {$vote->task_description}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $voterId = $context->senderName ?? $context->from;

        if ($vote->hasVoted($voterId)) {
            $previousEmoji = $vote->votes[$voterId] ?? '?';
            $reply  = "⚠️ *{$voterId}*, tu as deja vote {$previousEmoji} sur cette proposition.\n\n";
            $reply .= "_Pour changer ton vote : changer vote {$voteId} {$emoji}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $vote->addVote($voterId, $emoji);

        $emojiLabel = match ($emoji) {
            '👍'    => 'Approuve',
            '👎'    => 'Rejete',
            '❓'    => 'Abstention',
            default => $emoji,
        };

        $totalVotes = $this->getRealTotalVotes($vote);
        $approves   = $vote->getVoteCount('approve');

        $reply  = "✅ Vote enregistre !\n\n";
        $reply .= "🗳️ *Proposition #{$voteId}*\n";
        $reply .= "📋 {$vote->task_description}\n\n";
        $reply .= "👤 {$voterId} : {$emoji} {$emojiLabel}\n\n";
        $reply .= "📊 *Etat des votes :*\n";
        $reply .= $vote->formatStatus() . "\n";
        $reply .= $this->buildProgressBar($approves, $totalVotes, $vote->vote_quorum) . "\n";

        if ($vote->isQuorumReached()) {
            $vote->approve();
            $reply .= "\n🎉 *QUORUM ATTEINT — Proposition APPROUVEE !*\n";
            $reply .= "_La tache a ete validee par l'equipe._";
        } elseif ($vote->isRejected()) {
            $vote->reject();
            $reply .= "\n❌ *Proposition REJETEE par majorite.*\n";
            $reply .= "_Vous pouvez creer une nouvelle proposition amend\u00e9e._";
        } else {
            $remaining = $this->getRemainingVotesNeeded($vote);
            if ($remaining > 0) {
                $reply .= "\n_Encore {$remaining} vote(s) 👍 pour atteindre le quorum._";
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Vote cast', ['vote_id' => $voteId, 'emoji' => $emoji, 'voter' => $voterId]);

        return AgentResult::reply($reply, ['action' => 'vote_cast', 'vote_id' => $voteId, 'emoji' => $emoji]);
    }

    private function handleChangeVote(AgentContext $context, int $voteId, ?string $newEmoji): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.\n_Tapez /vote pour voir la liste des propositions._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $reply = "⚠️ La proposition #{$voteId} est cloturee. Impossible de modifier ton vote.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $voterId = $context->senderName ?? $context->from;

        if (!$vote->hasVoted($voterId)) {
            $reply  = "ℹ️ Tu n'as pas encore vote sur la proposition #{$voteId}.\n\n";
            $reply .= "_Vote directement : 👍 {$voteId} / 👎 {$voteId} / ❓ {$voteId}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if (!$newEmoji) {
            $currentEmoji = $vote->votes[$voterId] ?? '?';
            $reply  = "🔄 *Changer ton vote pour #{$voteId}*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "Vote actuel : {$currentEmoji}\n\n";
            $reply .= "_Indique ton nouveau vote :_\n";
            $reply .= "  changer vote {$voteId} 👍\n";
            $reply .= "  changer vote {$voteId} 👎\n";
            $reply .= "  changer vote {$voteId} ❓";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $previousEmoji = $vote->votes[$voterId] ?? '?';
        $vote->addVote($voterId, $newEmoji);

        $emojiLabel = match ($newEmoji) {
            '👍'    => 'Approuve',
            '👎'    => 'Rejete',
            '❓'    => 'Abstention',
            default => $newEmoji,
        };

        $totalVotes = $this->getRealTotalVotes($vote);
        $approves   = $vote->getVoteCount('approve');

        $reply  = "🔄 Vote modifie !\n\n";
        $reply .= "🗳️ *Proposition #{$voteId}*\n";
        $reply .= "📋 {$vote->task_description}\n\n";
        $reply .= "👤 {$voterId} : {$previousEmoji} → {$newEmoji} {$emojiLabel}\n\n";
        $reply .= "📊 *Etat des votes :*\n";
        $reply .= $vote->formatStatus() . "\n";
        $reply .= $this->buildProgressBar($approves, $totalVotes, $vote->vote_quorum) . "\n";

        if ($vote->isQuorumReached()) {
            $vote->approve();
            $reply .= "\n🎉 *QUORUM ATTEINT — Proposition APPROUVEE !*";
        } elseif ($vote->isRejected()) {
            $vote->reject();
            $reply .= "\n❌ *Proposition REJETEE par majorite.*";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Vote changed', ['vote_id' => $voteId, 'from' => $previousEmoji, 'to' => $newEmoji, 'voter' => $voterId]);

        return AgentResult::reply($reply, ['action' => 'vote_changed', 'vote_id' => $voteId, 'emoji' => $newEmoji]);
    }

    private function handleCancelProposal(AgentContext $context, int $voteId): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.\n_Tapez /vote pour voir la liste des propositions._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $statusLabel = match (true) {
                $vote->status === 'approved' => 'deja approuvee ✅',
                $this->isCancelled($vote)    => 'deja annulee 🚫',
                default                      => 'deja rejetee ❌',
            };
            $reply  = "⚠️ La proposition #{$voteId} est {$statusLabel}.\n";
            $reply .= "_Impossible d'annuler une proposition cloturee._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $actor = $context->senderName ?? $context->from;

        if ($vote->created_by !== $actor) {
            $reply  = "🚫 Seul le createur peut annuler sa proposition.\n";
            $reply .= "👤 Cree par : *{$vote->created_by}*\n\n";
            $reply .= "_Pour forcer la cloture : /decide reject {$voteId}_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $votes                  = $vote->votes ?? [];
        $votes['__cancelled_by'] = $actor;
        $vote->update(['votes' => $votes, 'status' => 'rejected']);

        $reply  = "🚫 *Proposition #{$voteId} annulee*\n\n";
        $reply .= "📋 {$vote->task_description}\n";
        $reply .= "👤 Annulee par : *{$actor}*\n\n";
        $reply .= "_Vous pouvez creer une nouvelle proposition avec /approve_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Proposal cancelled', ['vote_id' => $voteId, 'actor' => $actor]);

        return AgentResult::reply($reply, ['action' => 'proposal_cancelled', 'vote_id' => $voteId]);
    }

    private function handleShowVote(AgentContext $context, int $voteId): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.\n_Tapez /vote pour voir la liste des propositions._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $isCancelled = $this->isCancelled($vote);
        $statusEmoji = match (true) {
            $vote->status === 'pending'  => '⏳',
            $vote->status === 'approved' => '✅',
            $isCancelled                 => '🚫',
            default                      => '❌',
        };
        $statusLabel = match (true) {
            $vote->status === 'pending'  => 'En attente',
            $vote->status === 'approved' => 'Approuvee',
            $isCancelled                 => 'Annulee',
            default                      => 'Rejetee',
        };

        $reply  = "🗳️ *Proposition #{$vote->id}* {$statusEmoji}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📋 {$vote->task_description}\n\n";
        $reply .= "👤 Proposee par : *{$vote->created_by}*\n";
        $reply .= "📅 Creee le : " . $vote->created_at->format('d/m/Y H:i') . "\n";

        if ($vote->status === 'pending') {
            $deadline  = $vote->created_at->addHours(24);
            $expiresIn = $this->isExpired($vote)
                ? '⚠️ *Expiree*'
                : '⏰ Expire dans : *' . $deadline->diffForHumans(null, true) . '*';
            $reply .= "{$expiresIn}\n";
        }

        $reply .= "📊 Quorum requis : *{$vote->vote_quorum}%*\n";
        $reply .= "📌 Statut : *{$statusLabel}*\n\n";

        $votes     = $vote->votes ?? [];
        $realVotes = array_filter($votes, fn ($k) => !str_starts_with($k, '__'), ARRAY_FILTER_USE_KEY);

        if (empty($realVotes)) {
            $reply .= "_Aucun vote pour l'instant._\n";
        } else {
            $reply .= "*Detail des votes :*\n";
            foreach ($realVotes as $voter => $vEmoji) {
                $reply .= "  {$vEmoji} {$voter}\n";
            }
            $totalReal = count($realVotes);
            $approves  = $vote->getVoteCount('approve');
            $reply .= "\n" . $vote->formatStatus() . "\n";
            $reply .= $this->buildProgressBar($approves, $totalReal, $vote->vote_quorum);
        }

        if ($vote->status === 'pending' && !$this->isExpired($vote)) {
            $reply .= "\n\n_Votez : 👍 {$vote->id} / 👎 {$vote->id} / ❓ {$vote->id}_";
        }

        if ($vote->approved_at) {
            $reply .= "\n\n✅ Approuvee le : " . $vote->approved_at->format('d/m/Y H:i');
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'show_vote', 'vote_id' => $voteId]);
    }

    private function handleListVotes(AgentContext $context): AgentResult
    {
        $groupId      = $context->from;
        $pendingVotes = CollaborativeVote::getPendingForGroup($groupId);
        $count        = $pendingVotes->count();

        $reply  = "🗳️ *Votes en cours*";
        $reply .= $count > 0 ? " ({$count})\n" : "\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($pendingVotes->isEmpty()) {
            $reply .= "_Aucun vote en attente._\n\n";
            $reply .= "💡 Creez une proposition :\n";
            $reply .= "  _/approve Description de la tache_";
        } else {
            foreach ($pendingVotes as $vote) {
                $approves      = $vote->getVoteCount('approve');
                $rejects       = $vote->getVoteCount('reject');
                $totalVotes    = $this->getRealTotalVotes($vote);
                $age           = $vote->created_at->diffForHumans();
                $expiryWarning = $this->isExpired($vote) ? ' ⚠️ *Expiree*' : '';

                $reply .= "📋 *#{$vote->id}* — {$vote->task_description}{$expiryWarning}\n";
                $reply .= "   👤 {$vote->created_by} | 👍 {$approves} 👎 {$rejects} ({$totalVotes} votes) | {$age}\n";
                $reply .= "   " . $this->buildProgressBar($approves, $totalVotes, $vote->vote_quorum, 8) . "\n\n";
            }
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "_/vote [id]_ — Details | _👍 [id]_ — Approuver | _👎 [id]_ — Rejeter\n";
            $reply .= "_/rappel_ — Rappeler l'equipe";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'list_votes', 'count' => $count]);
    }

    private function handleVoteHistory(AgentContext $context): AgentResult
    {
        $groupId = $context->from;
        $votes   = CollaborativeVote::getRecentForGroup($groupId, 10);

        $reply  = "📊 *Historique des votes (10 derniers)*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($votes->isEmpty()) {
            $reply .= "_Aucun vote enregistre._\n";
        } else {
            $approved = $votes->where('status', 'approved')->count();
            $rejected = $votes->where('status', 'rejected')->count();
            $pending  = $votes->where('status', 'pending')->count();
            $reply .= "📈 Resume : ✅ {$approved} approuvees | ❌ {$rejected} rejetees | ⏳ {$pending} en cours\n\n";

            foreach ($votes as $vote) {
                $isCancelled = $this->isCancelled($vote);
                $statusEmoji = match (true) {
                    $vote->status === 'pending'  => '⏳',
                    $vote->status === 'approved' => '✅',
                    $isCancelled                 => '🚫',
                    default                      => '❌',
                };
                $date     = $vote->created_at->format('d/m');
                $closedAt = $vote->approved_at
                    ? ' — clos le ' . $vote->approved_at->format('d/m H:i')
                    : '';
                $reply .= "{$statusEmoji} *#{$vote->id}* [{$date}] {$vote->task_description}\n";
                $reply .= "   " . $vote->formatStatus() . "{$closedAt}\n\n";
            }
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'vote_history']);
    }

    private function handleTeamStats(AgentContext $context): AgentResult
    {
        $groupId  = $context->from;
        $allVotes = CollaborativeVote::byGroup($groupId)->orderByDesc('created_at')->limit(50)->get();

        if ($allVotes->isEmpty()) {
            $reply  = "📊 *Stats de l'equipe*\n\n_Aucune proposition pour l'instant._\n\n";
            $reply .= "💡 Creez votre premiere proposition avec _/approve_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'team_stats', 'empty' => true]);
        }

        $total        = $allVotes->count();
        $approved     = $allVotes->where('status', 'approved')->count();
        $rejected     = $allVotes->where('status', 'rejected')->count();
        $pending      = $allVotes->where('status', 'pending')->count();
        $approvalRate = $total > 0 ? round(($approved / $total) * 100) : 0;

        $voterStats = [];
        foreach ($allVotes as $vote) {
            foreach ($vote->votes ?? [] as $voter => $vEmoji) {
                if (str_starts_with($voter, '__')) {
                    continue;
                }
                if (!isset($voterStats[$voter])) {
                    $voterStats[$voter] = ['total' => 0, '👍' => 0, '👎' => 0, '❓' => 0];
                }
                $voterStats[$voter]['total']++;
                $voterStats[$voter][$vEmoji] = ($voterStats[$voter][$vEmoji] ?? 0) + 1;
            }
        }

        uasort($voterStats, fn ($a, $b) => $b['total'] <=> $a['total']);

        $reply  = "📊 *Statistiques Equipe*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "🗳️ *Propositions (50 dernieres)*\n";
        $reply .= "  Total : {$total}\n";
        $reply .= "  ✅ Approuvees : {$approved} ({$approvalRate}%)\n";
        $reply .= "  ❌ Rejetees/Annulees : {$rejected}\n";
        $reply .= "  ⏳ En attente : {$pending}\n\n";

        if (!empty($voterStats)) {
            $reply .= "👥 *Top 5 participants*\n";
            $rank = 1;
            foreach (array_slice($voterStats, 0, 5, true) as $voter => $stats) {
                $medal  = match ($rank) {
                    1       => '🥇',
                    2       => '🥈',
                    3       => '🥉',
                    default => "  {$rank}.",
                };
                $reply .= "{$medal} *{$voter}* — {$stats['total']} votes ";
                $reply .= "(👍 {$stats['👍']} | 👎 {$stats['👎']} | ❓ {$stats['❓']})\n";
                $rank++;
            }
        }

        $totalParticipants = count($voterStats);
        if ($totalParticipants > 0) {
            $totalVotesCast      = array_sum(array_column($voterStats, 'total'));
            $avgVotesPerProposal = $total > 0 ? round($totalVotesCast / $total, 1) : 0;
            $reply .= "\n📈 *Engagement*\n";
            $reply .= "  Participants actifs : {$totalParticipants}\n";
            $reply .= "  Votes par proposition : ~{$avgVotesPerProposal}\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Team stats viewed', ['group' => $groupId]);

        return AgentResult::reply($reply, ['action' => 'team_stats', 'total' => $total]);
    }

    private function handleMesPropositions(AgentContext $context): AgentResult
    {
        $actor     = $context->senderName ?? $context->from;
        $proposals = CollaborativeVote::byGroup($context->from)
            ->where('created_by', $actor)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $reply  = "📋 *Mes propositions*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($proposals->isEmpty()) {
            $reply .= "_Vous n'avez pas encore cree de proposition._\n\n";
            $reply .= "💡 Creez-en une :\n";
            $reply .= "  _/approve Description de la tache_\n";
            $reply .= "  _/approve 75% Tache avec quorum specifique_";
        } else {
            foreach ($proposals as $vote) {
                $isCancelled = $this->isCancelled($vote);
                $statusEmoji = match (true) {
                    $vote->status === 'pending'  => '⏳',
                    $vote->status === 'approved' => '✅',
                    $isCancelled                 => '🚫',
                    default                      => '❌',
                };
                $date          = $vote->created_at->format('d/m');
                $expiryWarning = ($vote->status === 'pending' && $this->isExpired($vote)) ? ' ⚠️ Expiree' : '';
                $expiresIn     = $vote->status === 'pending' && !$this->isExpired($vote)
                    ? ' — expire ' . $vote->created_at->addHours(24)->diffForHumans()
                    : '';

                $reply .= "{$statusEmoji} *#{$vote->id}* [{$date}] {$vote->task_description}{$expiryWarning}{$expiresIn}\n";
                $reply .= "   " . $vote->formatStatus() . "\n\n";
            }
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "_/vote [id]_ — Detail | _/annuler [id]_ — Annuler une proposition en cours";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Mes propositions viewed', ['actor' => $actor, 'count' => $proposals->count()]);

        return AgentResult::reply($reply, ['action' => 'mes_propositions', 'count' => $proposals->count()]);
    }

    private function handleRappelVotes(AgentContext $context): AgentResult
    {
        $groupId      = $context->from;
        $pendingVotes = CollaborativeVote::getPendingForGroup($groupId);

        if ($pendingVotes->isEmpty()) {
            $reply  = "✅ *Aucun vote en attente — tout est a jour !*\n\n";
            $reply .= "💡 Creez une proposition : _/approve Description_";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'rappel_votes', 'count' => 0]);
        }

        $actor = $context->senderName ?? $context->from;
        $count = $pendingVotes->count();

        $reply  = "🔔 *RAPPEL — {$count} vote(s) en attente* _(demande par {$actor})_\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        foreach ($pendingVotes as $vote) {
            $approves  = $vote->getVoteCount('approve');
            $totalReal = $this->getRealTotalVotes($vote);
            $remaining = $this->getRemainingVotesNeeded($vote);
            $expiresIn = $this->isExpired($vote)
                ? '⚠️ *Expiree*'
                : '⏰ ' . $vote->created_at->addHours(24)->diffForHumans(null, true) . ' restantes';

            $reply .= "📋 *#{$vote->id}* — {$vote->task_description}\n";
            $reply .= "   👤 {$vote->created_by} | {$expiresIn}\n";
            $reply .= "   " . $vote->formatStatus() . "\n";
            $reply .= "   " . $this->buildProgressBar($approves, $totalReal, $vote->vote_quorum, 8) . "\n";
            if (!$this->isExpired($vote) && $remaining > 0) {
                $reply .= "   _Encore {$remaining} vote(s) 👍 pour approuver._\n";
            }
            $reply .= "\n";
        }

        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "_Votez : 👍 [id] / 👎 [id] / ❓ [id]_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Vote reminder sent', ['group' => $groupId, 'pending_count' => $count]);

        return AgentResult::reply($reply, ['action' => 'rappel_votes', 'count' => $count]);
    }

    private function handleForceDecision(AgentContext $context, int $voteId, bool $approve): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.\n_Tapez /vote pour voir la liste des propositions._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $statusLabel = match (true) {
                $vote->status === 'approved' => 'approuvee ✅',
                $this->isCancelled($vote)    => 'annulee 🚫',
                default                      => 'rejetee ❌',
            };
            $reply = "⚠️ La proposition #{$voteId} est deja *{$statusLabel}*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $actor = $context->senderName ?? $context->from;

        if ($approve) {
            $vote->approve();
            $reply  = "✅ *Decision forcee — Proposition #{$voteId} APPROUVEE*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "👤 Decidee par : *{$actor}*\n\n";
            $reply .= "📊 Etat des votes au moment de la decision :\n";
            $reply .= $vote->formatStatus();
        } else {
            $vote->reject();
            $reply  = "❌ *Decision forcee — Proposition #{$voteId} REJETEE*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "👤 Decidee par : *{$actor}*\n\n";
            $reply .= "📊 Etat des votes au moment de la decision :\n";
            $reply .= $vote->formatStatus();
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Decision forced', ['vote_id' => $voteId, 'approved' => $approve, 'actor' => $actor]);

        return AgentResult::reply($reply, ['action' => 'force_decision', 'vote_id' => $voteId, 'approved' => $approve]);
    }

    private function handleSetQuorum(AgentContext $context, int $percent, ?int $voteId = null): AgentResult
    {
        $percent = max(1, min(100, $percent));

        if ($voteId !== null) {
            $vote = CollaborativeVote::find($voteId);
            if (!$vote) {
                $reply = "❌ Proposition #{$voteId} introuvable.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
            if ($vote->status !== 'pending') {
                $reply = "⚠️ La proposition #{$voteId} est cloturee. Impossible de modifier son quorum.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
            $vote->update(['vote_quorum' => $percent]);
            $reply  = "✅ *Quorum mis a jour : {$percent}% pour la proposition #{$voteId}*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "_Le quorum definit le % de votes 👍 requis pour approuver._";
        } else {
            $updated = CollaborativeVote::byGroup($context->from)
                ->pending()
                ->update(['vote_quorum' => $percent]);
            $reply  = "✅ *Quorum mis a jour : {$percent}%*\n\n";
            $reply .= "{$updated} proposition(s) en attente mise(s) a jour.\n";
            $reply .= "_Pour une proposition specifique : quorum 75% pour #42_\n";
            $reply .= "_Le quorum definit le % de votes 👍 requis pour approuver._";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'set_quorum', 'quorum' => $percent, 'vote_id' => $voteId]);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply  = "🗳️ *Votes Equipe — Commandes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📝 *Creer une proposition :*\n";
        $reply .= "  _/approve Deployer la v2.0 en prod_\n";
        $reply .= "  _/approve 75% Description (quorum custom)_\n";
        $reply .= "  _proposer Ajouter un dark mode_\n\n";
        $reply .= "🗳️ *Voter :*\n";
        $reply .= "  _👍 42_ — Approuver la proposition #42\n";
        $reply .= "  _👎 42_ — Rejeter\n";
        $reply .= "  _❓ 42_ — Abstention\n";
        $reply .= "  _voter pour 42_ / _voter contre 42_\n";
        $reply .= "  _changer vote 42 👍_ — Modifier son vote\n\n";
        $reply .= "📊 *Consulter :*\n";
        $reply .= "  _/vote_ — Votes en cours\n";
        $reply .= "  _/vote 42_ — Detail d'une proposition\n";
        $reply .= "  _/mes_ — Mes propositions creees\n";
        $reply .= "  _historique votes_ — Historique complet\n";
        $reply .= "  _/stats_ — Statistiques de l'equipe\n\n";
        $reply .= "🔔 *Rappels :*\n";
        $reply .= "  _/rappel_ — Rappeler l'equipe des votes en attente\n\n";
        $reply .= "⚡ *Administration :*\n";
        $reply .= "  _/annuler 42_ — Annuler sa proposition\n";
        $reply .= "  _/decide approve 42_ — Forcer l'approbation\n";
        $reply .= "  _/decide reject 42_ — Forcer le rejet\n";
        $reply .= "  _quorum 75%_ — Changer le seuil (toutes propositions)\n";
        $reply .= "  _quorum 75% pour #42_ — Changer pour une proposition\n";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'help']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Count real votes only, excluding internal __ metadata keys (e.g. __cancelled_by).
     * Workaround for CollaborativeVote::getTotalVotes() which counts all JSON keys.
     */
    private function getRealTotalVotes(CollaborativeVote $vote): int
    {
        $votes = $vote->votes ?? [];
        return count(array_filter($votes, fn ($k) => !str_starts_with($k, '__'), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Build a visual ASCII progress bar toward quorum.
     * Example: ████████░░ 80% (quorum: 60%) ✅
     */
    private function buildProgressBar(int $approves, int $total, int $quorum, int $width = 10): string
    {
        if ($total === 0) {
            return str_repeat('░', $width) . ' 0% 👍 (quorum: ' . $quorum . '%)';
        }

        $approvePercent = (int) round(($approves / $total) * 100);
        $filled         = (int) round(($approvePercent / 100) * $width);
        $filled         = max(0, min($width, $filled));
        $bar            = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
        $status         = $approvePercent >= $quorum ? ' ✅' : '';

        return "{$bar} {$approvePercent}% 👍 (quorum: {$quorum}%){$status}";
    }

    /**
     * Estimate how many more 👍 votes are needed to reach the quorum.
     */
    private function getRemainingVotesNeeded(CollaborativeVote $vote): int
    {
        $total = $this->getRealTotalVotes($vote);
        if ($total === 0) {
            return 1;
        }
        $approves = $vote->getVoteCount('approve');
        for ($extra = 1; $extra <= 100; $extra++) {
            $newPercent = (($approves + $extra) / ($total + $extra)) * 100;
            if ($newPercent >= $vote->vote_quorum) {
                return $extra;
            }
        }

        return 1;
    }

    /**
     * Check whether a proposal has the cancellation marker in its votes JSON.
     */
    private function isCancelled(CollaborativeVote $vote): bool
    {
        $votes = $vote->votes ?? [];
        return isset($votes['__cancelled_by']);
    }

    /**
     * Check whether a proposal has exceeded its 24-hour window.
     */
    private function isExpired(CollaborativeVote $vote): bool
    {
        return $vote->created_at->addHours(24)->isPast();
    }
}
