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
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if (preg_match('/^\/(?:vote|approve|decide)\b/iu', $body)) {
            return true;
        }
        if (preg_match('/\b(vote|voter|approve|consensus|sondage|poll|proposer|soumettre)\b/iu', $body)) {
            return true;
        }
        if (preg_match('/^(👍|👎|❓)\s*\d+\s*$/u', $body)) {
            return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        // /vote <id> — show votes for a specific proposal
        if (preg_match('/^\/vote\s+(\d+)\s*$/iu', $body, $m)) {
            return $this->handleShowVote($context, (int) $m[1]);
        }

        // /vote — list all pending votes
        if (preg_match('/^\/vote\s*$/iu', $body)) {
            return $this->handleListVotes($context);
        }

        // /approve <description> — create a new vote proposal
        if (preg_match('/^\/approve\s+(.+)$/iu', $body, $m)) {
            return $this->handleCreateProposal($context, trim($m[1]));
        }

        // /decide <action> on <id> — force a decision
        if (preg_match('/^\/decide\s+(approve|reject|approuver|rejeter|valider|annuler)\s+(\d+)\s*$/iu', $body, $m)) {
            $action = mb_strtolower($m[1]);
            $isApprove = in_array($action, ['approve', 'approuver', 'valider']);
            return $this->handleForceDecision($context, (int) $m[2], $isApprove);
        }

        // Natural language: "propose ...", "proposer ...", "soumettre ..."
        if (preg_match('/\b(propos(?:e|er)|soumettre|submit|suggest)\s+(.+)/iu', $lower, $m)) {
            return $this->handleCreateProposal($context, trim($m[2]));
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

        // "votes en cours", "pending votes", "mes votes"
        if (preg_match('/\b(votes?\s+en\s+cours|pending\s+votes|mes\s+votes|list\s+votes)\b/iu', $lower)) {
            return $this->handleListVotes($context);
        }

        // "historique votes", "vote history"
        if (preg_match('/\b(historique\s+votes?|vote\s+history|resultats?\s+votes?)\b/iu', $lower)) {
            return $this->handleVoteHistory($context);
        }

        // "set quorum <percent>"
        if (preg_match('/\b(?:set\s+)?quorum\s+(\d+)\s*%?\s*$/iu', $body, $m)) {
            return $this->handleSetQuorum($context, (int) $m[1]);
        }

        return $this->handleHelp($context);
    }

    private function handleCreateProposal(AgentContext $context, string $description): AgentResult
    {
        $groupId = $context->from;

        $vote = CollaborativeVote::create([
            'message_group_id' => $groupId,
            'task_description' => $description,
            'vote_quorum' => 60,
            'created_by' => $context->senderName ?? $context->from,
            'status' => 'pending',
            'votes' => [],
        ]);

        $reply = "🗳️ *Nouvelle proposition #" . $vote->id . "*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📋 {$description}\n\n";
        $reply .= "👤 Proposee par : *" . ($context->senderName ?? $context->from) . "*\n";
        $reply .= "📊 Quorum requis : *{$vote->vote_quorum}%*\n";
        $reply .= "⏰ Expire dans : *24h*\n\n";
        $reply .= "━━━━━━━━━━━━━━━━\n";
        $reply .= "*Comment voter :*\n";
        $reply .= "  👍 {$vote->id} — Approuver\n";
        $reply .= "  👎 {$vote->id} — Rejeter\n";
        $reply .= "  ❓ {$vote->id} — Abstention\n\n";
        $reply .= "_Ou tapez : voter pour {$vote->id} / voter contre {$vote->id}_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Proposal created', ['vote_id' => $vote->id, 'description' => $description]);

        return AgentResult::reply($reply, ['action' => 'proposal_created', 'vote_id' => $vote->id]);
    }

    private function handleCastVote(AgentContext $context, int $voteId, string $emoji): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $statusLabel = $vote->status === 'approved' ? 'approuvee' : 'rejetee';
            $reply = "⚠️ La proposition #{$voteId} est deja *{$statusLabel}*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $voterId = $context->senderName ?? $context->from;

        if ($vote->hasVoted($voterId)) {
            $reply = "⚠️ *{$voterId}*, tu as deja vote sur cette proposition.\n";
            $reply .= "Vote actuel : " . ($vote->votes[$voterId] ?? '?');
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $vote->addVote($voterId, $emoji);

        $emojiLabel = match ($emoji) {
            '👍' => 'Approuve',
            '👎' => 'Rejete',
            '❓' => 'Abstention',
            default => $emoji,
        };

        $reply = "✅ Vote enregistre !\n\n";
        $reply .= "🗳️ *Proposition #{$voteId}*\n";
        $reply .= "📋 {$vote->task_description}\n\n";
        $reply .= "👤 {$voterId} : {$emoji} {$emojiLabel}\n\n";
        $reply .= "📊 *Etat des votes :*\n";
        $reply .= $vote->formatStatus() . "\n";

        // Check if quorum is reached
        if ($vote->isQuorumReached()) {
            $vote->approve();
            $reply .= "\n🎉 *QUORUM ATTEINT — Proposition APPROUVEE !*\n";
            $reply .= "La tache sera executee automatiquement.";
        } elseif ($vote->isRejected()) {
            $vote->reject();
            $reply .= "\n❌ *Proposition REJETEE par majorite.*";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Vote cast', ['vote_id' => $voteId, 'emoji' => $emoji, 'voter' => $voterId]);

        return AgentResult::reply($reply, ['action' => 'vote_cast', 'vote_id' => $voteId, 'emoji' => $emoji]);
    }

    private function handleShowVote(AgentContext $context, int $voteId): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $statusEmoji = match ($vote->status) {
            'pending' => '⏳',
            'approved' => '✅',
            'rejected' => '❌',
            default => '❓',
        };

        $reply = "🗳️ *Proposition #{$vote->id}* {$statusEmoji}\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📋 {$vote->task_description}\n\n";
        $reply .= "👤 Proposee par : *{$vote->created_by}*\n";
        $reply .= "📅 Creee le : " . $vote->created_at->format('d/m/Y H:i') . "\n";
        $reply .= "📊 Quorum requis : *{$vote->vote_quorum}%*\n";
        $reply .= "📌 Statut : *{$vote->status}*\n\n";

        $votes = $vote->votes ?? [];
        if (empty($votes)) {
            $reply .= "_Aucun vote pour l'instant._\n";
        } else {
            $reply .= "*Detail des votes :*\n";
            foreach ($votes as $voter => $emoji) {
                $reply .= "  {$emoji} {$voter}\n";
            }
            $reply .= "\n" . $vote->formatStatus();
        }

        if ($vote->status === 'pending') {
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
        $groupId = $context->from;
        $pendingVotes = CollaborativeVote::getPendingForGroup($groupId);

        $reply = "🗳️ *Votes en cours*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($pendingVotes->isEmpty()) {
            $reply .= "_Aucun vote en attente._\n\n";
            $reply .= "💡 Creez une proposition :\n";
            $reply .= "  _/approve Description de la tache_";
        } else {
            foreach ($pendingVotes as $vote) {
                $totalVotes = $vote->getTotalVotes();
                $approves = $vote->getVoteCount('approve');
                $rejects = $vote->getVoteCount('reject');
                $age = $vote->created_at->diffForHumans();

                $reply .= "📋 *#{$vote->id}* — {$vote->task_description}\n";
                $reply .= "   👤 {$vote->created_by} | 👍 {$approves} 👎 {$rejects} ({$totalVotes} votes) | {$age}\n\n";
            }
            $reply .= "━━━━━━━━━━━━━━━━\n";
            $reply .= "_/vote [id]_ — Details | _👍 [id]_ — Approuver | _👎 [id]_ — Rejeter";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'list_votes', 'count' => $pendingVotes->count()]);
    }

    private function handleVoteHistory(AgentContext $context): AgentResult
    {
        $groupId = $context->from;
        $votes = CollaborativeVote::getRecentForGroup($groupId, 10);

        $reply = "📊 *Historique des votes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";

        if ($votes->isEmpty()) {
            $reply .= "_Aucun vote enregistre._\n";
        } else {
            foreach ($votes as $vote) {
                $statusEmoji = match ($vote->status) {
                    'pending' => '⏳',
                    'approved' => '✅',
                    'rejected' => '❌',
                    default => '❓',
                };
                $date = $vote->created_at->format('d/m');
                $reply .= "{$statusEmoji} *#{$vote->id}* [{$date}] {$vote->task_description}\n";
                $reply .= "   {$vote->formatStatus()}\n\n";
            }
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'vote_history']);
    }

    private function handleForceDecision(AgentContext $context, int $voteId, bool $approve): AgentResult
    {
        $vote = CollaborativeVote::find($voteId);

        if (!$vote) {
            $reply = "❌ Proposition #{$voteId} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($vote->status !== 'pending') {
            $reply = "⚠️ La proposition #{$voteId} n'est plus en attente (statut: {$vote->status}).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $actor = $context->senderName ?? $context->from;

        if ($approve) {
            $vote->approve();
            $reply = "✅ *Decision forcee — Proposition #{$voteId} APPROUVEE*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "👤 Decidee par : *{$actor}*\n";
            $reply .= "📊 Votes au moment de la decision :\n";
            $reply .= $vote->formatStatus();
        } else {
            $vote->reject();
            $reply = "❌ *Decision forcee — Proposition #{$voteId} REJETEE*\n\n";
            $reply .= "📋 {$vote->task_description}\n";
            $reply .= "👤 Decidee par : *{$actor}*\n";
            $reply .= "📊 Votes au moment de la decision :\n";
            $reply .= $vote->formatStatus();
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Decision forced', ['vote_id' => $voteId, 'approved' => $approve, 'actor' => $actor]);

        return AgentResult::reply($reply, ['action' => 'force_decision', 'vote_id' => $voteId, 'approved' => $approve]);
    }

    private function handleSetQuorum(AgentContext $context, int $percent): AgentResult
    {
        $percent = max(1, min(100, $percent));

        // Update quorum for all pending votes in this group
        $updated = CollaborativeVote::byGroup($context->from)
            ->pending()
            ->update(['vote_quorum' => $percent]);

        $reply = "✅ *Quorum mis a jour : {$percent}%*\n\n";
        $reply .= "{$updated} proposition(s) en attente mise(s) a jour.\n";
        $reply .= "\n_Le quorum definit le % de votes 👍 requis pour approuver._";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'set_quorum', 'quorum' => $percent]);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "🗳️ *Votes Equipe — Commandes*\n";
        $reply .= "━━━━━━━━━━━━━━━━\n\n";
        $reply .= "📝 *Creer une proposition :*\n";
        $reply .= "  _/approve Deployer la v2.0 en prod_\n";
        $reply .= "  _proposer Ajouter un dark mode_\n\n";
        $reply .= "🗳️ *Voter :*\n";
        $reply .= "  _👍 42_ — Approuver la proposition #42\n";
        $reply .= "  _👎 42_ — Rejeter\n";
        $reply .= "  _❓ 42_ — Abstention\n";
        $reply .= "  _voter pour 42_ / _voter contre 42_\n\n";
        $reply .= "📊 *Consulter :*\n";
        $reply .= "  _/vote_ — Votes en cours\n";
        $reply .= "  _/vote 42_ — Detail d'une proposition\n";
        $reply .= "  _historique votes_ — Historique complet\n\n";
        $reply .= "⚡ *Administration :*\n";
        $reply .= "  _/decide approve 42_ — Forcer l'approbation\n";
        $reply .= "  _/decide reject 42_ — Forcer le rejet\n";
        $reply .= "  _quorum 75%_ — Changer le seuil de vote\n";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'help']);
    }
}
