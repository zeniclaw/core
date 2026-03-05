<?php
// Generate a 1200x630 OG image for social sharing
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

$w = 1200;
$h = 630;
$img = imagecreatetruecolor($w, $h);

// Colors
$bg       = imagecolorallocate($img, 10, 14, 23);
$white    = imagecolorallocate($img, 241, 245, 249);
$gray     = imagecolorallocate($img, 148, 163, 184);
$muted    = imagecolorallocate($img, 100, 116, 139);
$blue     = imagecolorallocate($img, 59, 130, 246);
$purple   = imagecolorallocate($img, 139, 92, 246);
$pink     = imagecolorallocate($img, 236, 72, 153);
$card     = imagecolorallocate($img, 26, 31, 46);

// Background
imagefill($img, 0, 0, $bg);

// Top gradient bar
for ($x = 0; $x < $w; $x++) {
    $ratio = $x / $w;
    if ($ratio < 0.5) {
        $r2 = $ratio * 2;
        $r = (int)(59 + (139 - 59) * $r2);
        $g = (int)(130 + (92 - 130) * $r2);
        $b = (int)(246 + (246 - 246) * $r2);
    } else {
        $r2 = ($ratio - 0.5) * 2;
        $r = (int)(139 + (236 - 139) * $r2);
        $g = (int)(92 + (72 - 92) * $r2);
        $b = (int)(246 + (153 - 246) * $r2);
    }
    $c = imagecolorallocate($img, $r, $g, $b);
    imagefilledrectangle($img, $x, 0, $x, 4, $c);
}

// Logo square
imagefilledrectangle($img, 80, 70, 134, 124, $blue);
imagestring($img, 5, 100, 88, 'Z', $white);

// Logo text
imagestring($img, 5, 148, 92, 'ZeniClaw', $white);

// Title line 1
$font = 5; // Built-in font (no TTF needed)
$titleY = 200;
// Use larger text by repeating with imagestring (limited without TTF)
// For better results, use imagestring which is limited but works everywhere

// Draw big text line by line
$lines = [
    [80, 180, 'YOUR AI ARMY,', $white],
    [80, 210, 'ONE WHATSAPP AWAY', $blue],
    [80, 270, 'Self-hosted AI platform with 19 specialized agents.', $gray],
    [80, 295, 'Manage projects, track finances, take meeting notes,', $gray],
    [80, 320, 'learn with flashcards - all from a single WhatsApp chat.', $gray],
    [80, 400, '19 Agents  |  Open Source  |  100% Self-Hosted', $muted],
    [80, 560, 'zeniclaw.com', $muted],
];

foreach ($lines as [$x, $y, $text, $color]) {
    imagestring($img, $font, $x, $y, $text, $color);
}

// Decorative card boxes on the right
$agents = [
    [820, 140, 'ChatAgent',     $blue],
    [820, 190, 'DevAgent',      $purple],
    [820, 240, 'ReminderAgent', $pink],
    [820, 290, 'FinanceAgent',  $blue],
    [820, 340, 'MusicAgent',    $purple],
    [820, 390, 'TodoAgent',     $pink],
];

foreach ($agents as [$ax, $ay, $label, $accent]) {
    imagefilledrectangle($img, $ax, $ay, $ax + 300, $ay + 38, $card);
    imagerectangle($img, $ax, $ay, $ax + 300, $ay + 38, $accent);
    imagefilledrectangle($img, $ax, $ay, $ax + 4, $ay + 38, $accent);
    imagestring($img, 4, $ax + 16, $ay + 12, $label, $white);
}

imagepng($img);
imagedestroy($img);
