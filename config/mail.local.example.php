<?php
/**
 * E-mail settings. Copy this file to mail.local.php (same folder) and fill it in.
 * mail.local.php is kept out of version control: it holds an API key.
 *
 * On a hosted deploy (Railway) set the DISINFENTRY_MAIL_* environment variables instead -
 * see includes/mail.php for the names.
 *
 * Resend  (resend.com): create an API key. Until you verify a domain, Resend only delivers
 *         to your own account's address and only from onboarding@resend.dev - fine for a
 *         test, not for real users. Verify a domain to send to anyone.
 * Brevo   (brevo.com):  create an API key (SMTP & API -> API keys) and verify a sender
 *         address under Senders & IP.
 */

return [
    'provider' => 'resend',                                  // 'resend' or 'brevo'
    'api_key'  => 'PASTE-YOUR-API-KEY-HERE',
    'from'     => 'DisinfEntry <noreply@your-verified-domain.example>',
    // 'base_url' => 'http://localhost/DisinfEntry',         // optional: adds a sign-in link
];
