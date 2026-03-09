<?php

namespace App\Services\GameEngine;

interface GameInterface
{
    public function getType(): string;
    public function initGame(): array;
    public function validateAnswer(string $userAnswer, array $gameState): array;
    public function getScore(array $gameState): int;
    public function formatQuestion(array $gameState): string;
    public function isFinished(array $gameState): bool;
}
