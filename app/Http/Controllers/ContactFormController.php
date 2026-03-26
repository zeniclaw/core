<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ContactFormController extends Controller
{
    public function send(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:200',
            'company' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',
            'message' => 'required|string|min:10|max:5000',
        ]);

        // Rate limit: 3 per hour per IP
        $key = 'contact-form:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('error', 'Trop de messages envoyés. Réessayez plus tard. / Too many messages sent. Please try again later.');
        }
        RateLimiter::hit($key, 3600);

        try {
            Mail::raw($this->buildEmailBody($validated), function ($mail) use ($validated) {
                $mail->to('guillaume@zenibiz.com')
                     ->replyTo($validated['email'], $validated['name'])
                     ->subject('ZeniClaw Contact: ' . $validated['name'] . ($validated['company'] ? ' (' . $validated['company'] . ')' : ''));
            });

            Log::info('Contact form submitted', ['name' => $validated['name'], 'email' => $validated['email']]);

            return back()->with('contact_success', true);
        } catch (\Throwable $e) {
            Log::error('Contact form email failed: ' . $e->getMessage());
            return back()->with('error', "Erreur d'envoi. Contactez-nous directement : guillaume@zenibiz.com");
        }
    }

    private function buildEmailBody(array $data): string
    {
        $body = "Nouveau message depuis ZeniClaw.io\n";
        $body .= "================================\n\n";
        $body .= "Nom: {$data['name']}\n";
        $body .= "Email: {$data['email']}\n";
        if (!empty($data['company'])) {
            $body .= "Entreprise: {$data['company']}\n";
        }
        if (!empty($data['phone'])) {
            $body .= "Téléphone: {$data['phone']}\n";
        }
        $body .= "\nMessage:\n{$data['message']}\n";
        return $body;
    }
}
