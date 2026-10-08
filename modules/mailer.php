<?php
declare(strict_types=1);

function app_mail_settings(): array
{
    $path = __DIR__ . '/../.runtime/app-data/mail.php';
    if (!is_file($path)) return [];
    $settings = require $path;
    if (!is_array($settings)) return [];
    return $settings;
}

function app_public_url(): string
{
    $settings = app_mail_settings();
    $configured = trim((string)(getenv('VERTICE_APP_URL') ?: ($settings['app_url'] ?? '')));
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https','http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('A URL pública da aplicação está inválida.');
        }
        return rtrim($configured, '/');
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) throw new RuntimeException('O endereço público da aplicação não está configurado.');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function app_mail_is_configured(): bool
{
    $settings = app_mail_settings();
    return in_array((string)($settings['encryption'] ?? ''), ['ssl', 'tls', 'none'], true)
        && trim((string)($settings['host'] ?? '')) !== ''
        && (int)($settings['port'] ?? 0) > 0
        && trim((string)($settings['username'] ?? '')) !== ''
        && (string)($settings['password'] ?? '') !== ''
        && filter_var((string)($settings['from_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
}

function app_smtp_read($socket, array $expected): string
{
    $response = '';
    do {
        $line = fgets($socket, 2048);
        if ($line === false) throw new RuntimeException('A conexão SMTP foi encerrada inesperadamente.');
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) throw new RuntimeException('O servidor SMTP recusou uma etapa de envio (código ' . $code . ').');
    return $response;
}

function app_smtp_command($socket, string $command, array $expected): string
{
    if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('Não foi possível enviar o comando SMTP.');
    return app_smtp_read($socket, $expected);
}

function app_send_email(string $to, string $subject, string $html, string $text): void
{
    if (!app_mail_is_configured()) throw new RuntimeException('O envio de e-mail não está configurado.');
    $settings = app_mail_settings();
    $host = trim((string)$settings['host']);
    $port = (int)$settings['port'];
    $encryption = (string)$settings['encryption'];
    $username = (string)$settings['username'];
    $password = (string)$settings['password'];
    $from = (string)$settings['from_email'];
    $fromName = trim(str_replace(["\r", "\n"], '', (string)($settings['from_name'] ?? 'AFFILIEY')));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Endereço de e-mail inválido.');
    if (preg_match('/[\r\n]/', $subject)) throw new InvalidArgumentException('Assunto de e-mail inválido.');

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) throw new RuntimeException('Não foi possível conectar ao servidor SMTP (código ' . $errorCode . ').');
    stream_set_timeout($socket, 15);
    try {
        app_smtp_read($socket, [220]);
        $hello = preg_replace('/[^A-Za-z0-9.-]/', '', (string)(gethostname() ?: 'localhost')) ?: 'localhost';
        app_smtp_command($socket, 'EHLO ' . $hello, [250]);
        if ($encryption === 'tls') {
            app_smtp_command($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) throw new RuntimeException('Não foi possível iniciar TLS para SMTP.');
            app_smtp_command($socket, 'EHLO ' . $hello, [250]);
        }
        app_smtp_command($socket, 'AUTH LOGIN', [334]);
        app_smtp_command($socket, base64_encode($username), [334]);
        app_smtp_command($socket, base64_encode($password), [235]);
        app_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        app_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        app_smtp_command($socket, 'DATA', [354]);

        $boundary = '=_vertice_' . bin2hex(random_bytes(12));
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . (parse_url('https://' . $host, PHP_URL_HOST) ?: 'localhost') . '>';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: ' . $messageId,
            'From: ' . $encodedFromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $data = preg_replace('/(^|\r\n)\./', '$1..', $data) ?? $data;
        if (fwrite($socket, $data . "\r\n.\r\n") === false) throw new RuntimeException('Não foi possível transmitir a mensagem SMTP.');
        app_smtp_read($socket, [250]);
        app_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function app_email_page(string $title, string $content): string
{
    return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#F9FAFB;font-family:Arial,sans-serif;color:#111827"><div style="max-width:560px;margin:32px auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:32px"><p style="font-weight:800;letter-spacing:1px;color:#111827">AFFILIEY</p><h1 style="font-size:22px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $content . '<p style="margin-top:32px;color:#6b7280;font-size:12px">Se você não esperava esta mensagem, pode ignorá-la.</p></div></body></html>';
}
