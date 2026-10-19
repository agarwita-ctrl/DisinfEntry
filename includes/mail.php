<?php
/**
 * Outgoing e-mail through an HTTPS e-mail API (Resend or Brevo).
 *
 * Why an API and not SMTP or PHP's mail(): hosted platforms such as Railway block outbound
 * SMTP on their lower plans, and mail() needs a mail server the container does not have.
 * An HTTPS call works from anywhere the app can reach the internet.
 *
 * Configuration - config/mail.local.php (kept out of version control; see
 * mail.local.example.php) or environment variables, in that order:
 *
 *   provider   DISINFENTRY_MAIL_PROVIDER   "resend" or "brevo"
 *   api_key    DISINFENTRY_MAIL_API_KEY    the provider's API key (a secret - never commit it)
 *   from       DISINFENTRY_MAIL_FROM       "DisinfEntry <noreply@your-domain>" or a bare address;
 *                                          must be a sender the provider has verified
 *   base_url   DISINFENTRY_BASE_URL        optional, e.g. https://your-app.up.railway.app;
 *                                          adds a sign-in link to the e-mail
 *   endpoint   DISINFENTRY_MAIL_ENDPOINT   optional override of the provider URL (for testing)
 */

declare(strict_types=1);

const MAIL_PROVIDERS = [
    'resend' => 'https://api.resend.com/emails',
    'brevo'  => 'https://api.brevo.com/v3/smtp/email',
];

function mail_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $file  = __DIR__ . '/../config/mail.local.php';
    $local = is_file($file) ? (array) require $file : [];
    $pick  = static fn(string $key, string $env): string =>
        trim((string) ($local[$key] ?? getenv($env) ?: ''));

    return $cfg = [
        'provider' => strtolower($pick('provider', 'DISINFENTRY_MAIL_PROVIDER')),
        'api_key'  => $pick('api_key', 'DISINFENTRY_MAIL_API_KEY'),
        'from'     => $pick('from', 'DISINFENTRY_MAIL_FROM'),
        'base_url' => rtrim($pick('base_url', 'DISINFENTRY_BASE_URL'), '/'),
        'endpoint' => $pick('endpoint', 'DISINFENTRY_MAIL_ENDPOINT'),
    ];
}

/** True when a provider, key and sender are all set. */
function mail_configured(): bool
{
    $c = mail_config();
    return isset(MAIL_PROVIDERS[$c['provider']]) && $c['api_key'] !== '' && $c['from'] !== '';
}

/** "jane.doe@example.com" -> "j*******@example.com": enough to recognise, not to harvest. */
function mail_mask(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return $email;
    }
    return mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
}

/** Splits "Name <addr@host>" into [name, address]. */
function mail_parse_from(string $from): array
{
    if (preg_match('/^\s*(.*?)\s*<\s*([^<>\s]+)\s*>\s*$/', $from, $m)) {
        return [trim($m[1], " \t\"'"), $m[2]];
    }
    return ['', trim($from)];
}

/**
 * Sends one message. Returns null on success, or a short reason safe to show an
 * administrator (it never contains the API key).
 */
function send_mail(string $toEmail, string $toName, string $subject, string $text, string $html): ?string
{
    if (!mail_configured()) {
        return 'E-mail is not configured on this server.';
    }
    if (!function_exists('curl_init')) {
        return 'The PHP curl extension is missing, so e-mail cannot be sent.';
    }

    $c = mail_config();
    [$fromName, $fromAddr] = mail_parse_from($c['from']);
    $url = $c['endpoint'] !== '' ? $c['endpoint'] : MAIL_PROVIDERS[$c['provider']];

    if ($c['provider'] === 'resend') {
        $headers = ['Authorization: Bearer ' . $c['api_key'], 'Content-Type: application/json'];
        $payload = [
            'from'    => $c['from'],
            'to'      => [$toEmail],
            'subject' => $subject,
            'text'    => $text,
            'html'    => $html,
        ];
    } else {   // brevo
        $headers = ['api-key: ' . $c['api_key'], 'Content-Type: application/json', 'Accept: application/json'];
        $payload = [
            'sender'      => array_filter(['name' => $fromName, 'email' => $fromAddr]),
            'to'          => [array_filter(['email' => $toEmail, 'name' => $toName])],
            'subject'     => $subject,
            'textContent' => $text,
            'htmlContent' => $html,
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('mail: transport failure: ' . $error);
        return 'Could not reach the e-mail service. Check the server\'s internet access and try again.';
    }
    if ($code >= 200 && $code < 300) {
        return null;
    }

    $detail = '';
    $data   = json_decode((string) $body, true);
    if (is_array($data)) {
        $detail = (string) ($data['message'] ?? $data['error'] ?? '');
    }
    error_log(sprintf('mail: %s answered HTTP %d: %s', $c['provider'], $code, mb_substr((string) $body, 0, 300)));

    return sprintf('The e-mail service refused the message (HTTP %d)%s',
        $code, $detail !== '' ? ': ' . mb_substr($detail, 0, 160) : '.');
}

/**
 * Mails a temporary password. $reason is "created" or "reset". Returns null on success,
 * or a reason string.
 */
function send_temp_password(array $user, string $tempPassword, string $reason): ?string
{
    $system = (string) setting('system_name', 'DisinfEntry');
    $farm   = (string) setting('farm_name', '');
    $link   = mail_config()['base_url'];

    $headline = $reason === 'created'
        ? 'An account has been created for you'
        : 'Your password was reset by an administrator';

    $subject = sprintf('%s - %s', $system, $reason === 'created' ? 'your new account' : 'your temporary password');

    $text = implode("\n", array_filter([
        'Hello ' . $user['full_name'] . ',',
        '',
        $headline . ' on ' . $system . ($farm !== '' ? ' (' . $farm . ')' : '') . '.',
        '',
        'Username:           ' . $user['username'],
        'Temporary password: ' . $tempPassword,
        '',
        'Sign in with these, then choose your own password when asked. The temporary password',
        'stops working as soon as you have set a new one.',
        $link !== '' ? '' : null,
        $link !== '' ? 'Sign in: ' . $link . '/login.php' : null,
        '',
        'If you were not expecting this, tell your administrator.',
    ], static fn($l) => $l !== null));

    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;max-width:520px">'
        . '<p>Hello ' . $h($user['full_name']) . ',</p>'
        . '<p>' . $h($headline) . ' on <strong>' . $h($system) . '</strong>'
        . ($farm !== '' ? ' (' . $h($farm) . ')' : '') . '.</p>'
        . '<table style="border-collapse:collapse;margin:12px 0">'
        . '<tr><td style="padding:4px 12px 4px 0;color:#666">Username</td><td><strong>' . $h($user['username']) . '</strong></td></tr>'
        . '<tr><td style="padding:4px 12px 4px 0;color:#666">Temporary password</td>'
        . '<td><strong style="font-family:Consolas,monospace;font-size:17px;letter-spacing:1px">' . $h($tempPassword) . '</strong></td></tr>'
        . '</table>'
        . '<p>Sign in with these, then choose your own password when asked. The temporary password stops working as soon as you have set a new one.</p>'
        . ($link !== '' ? '<p><a href="' . $h($link . '/login.php') . '">Sign in to ' . $h($system) . '</a></p>' : '')
        . '<p style="color:#888;font-size:13px">If you were not expecting this, tell your administrator.</p>'
        . '</div>';

    return send_mail((string) $user['email'], (string) $user['full_name'], $subject, $text, $html);
}
