<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/integrations.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function webhook_reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') webhook_reply(405, ['ok' => false, 'message' => 'Use POST.']);
$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw === false || strlen($raw) > 1048576) webhook_reply(413, ['ok' => false, 'message' => 'Payload acima do limite de 1 MB.']);
$connectionId = (string)($_GET['connection'] ?? '');
if ($connectionId === '') webhook_reply(400, ['ok' => false, 'message' => 'Conexão ausente.']);
try {
    $find = app_db()->prepare('SELECT platform FROM integration_connections WHERE id=?');
    $find->execute([$connectionId]);
    $platform = $find->fetchColumn();
    if ($platform === 'kiwify') {
        $signature = (string)($_GET['signature'] ?? '');
        if ($signature === '') webhook_reply(401, ['ok' => false, 'message' => 'Assinatura Kiwify ausente.']);
        $result = kiwify_handle_webhook($connectionId, $signature, $raw);
    } elseif ($platform === 'hotmart') {
        $hottok = (string)($_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? '');
        if ($hottok === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) if (strcasecmp((string)$name, 'X-HOTMART-HOTTOK') === 0) { $hottok = (string)$value; break; }
        }
        $result = hotmart_handle_webhook($connectionId, $hottok, $raw);
    } elseif ($platform === 'eduzz') {
        $result = eduzz_handle_webhook($connectionId, $raw);
    } elseif ($platform === 'applyfy') {
        $token = (string)($_GET['token'] ?? '');
        $result = applyfy_handle_webhook($connectionId, $token, $raw);
    } else webhook_reply(404, ['ok' => false, 'message' => 'Conexão não encontrada.']);
    webhook_reply(200, ['ok' => true, 'duplicate' => (bool)($result['duplicate'] ?? false), 'ignored' => (bool)($result['ignored'] ?? false), 'message' => $result['message'] ?? 'Evento processado.']);
} catch (DomainException $e) {
    $authFailure = in_array($e->getMessage(), ['Assinatura Kiwify inválida.', 'Autenticação Hotmart inválida.', 'Autenticação do webhook Eduzz inválida.', 'Autenticação do webhook Applyfy inválida.', 'Conexão não autorizada.'], true);
    if (!$authFailure) integration_record_webhook_failure($connectionId, $raw, 'Webhook rejeitado: ' . $e->getMessage());
    $code = $authFailure ? 401 : 400;
    webhook_reply($code, ['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    integration_record_webhook_failure($connectionId, $raw, 'Falha interna ao processar webhook; a plataforma poderá reenviar.');
    webhook_reply(500, ['ok' => false, 'message' => 'Falha ao processar o evento. A plataforma poderá reenviar.']);
}
