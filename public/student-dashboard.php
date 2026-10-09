<?php
declare(strict_types=1);
try {
    require __DIR__ . '/affiliate-dashboard.php';
} catch (Throwable $error) {
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log('[AFFILIEY] Student dashboard failed [' . $reference . ']: ' . $error);
    http_response_code(503);
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#111827">
      <title>Área do aluno temporariamente indisponível — AFFILIEY</title>
      <link rel="icon" type="image/png" href="/brand/affiliey-favicon.png">
      <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f7f8fa;color:#111827;font:16px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.card{width:min(560px,100%);padding:36px;border:1px solid #e7eaf0;border-radius:22px;background:#fff;box-shadow:0 20px 60px #11182712}.brand{width:170px;max-width:60%;height:auto;margin-bottom:34px}.eyebrow{color:#b45309;font-size:12px;font-weight:750;letter-spacing:.12em;text-transform:uppercase}h1{font-size:clamp(27px,6vw,36px);line-height:1.15;letter-spacing:-.04em;margin:10px 0 12px}p{color:#667085;margin:0 0 22px}.reference{padding:11px 13px;border-radius:10px;background:#f7f8fa;color:#667085;font-size:13px}.reference strong{color:#111827}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}.button{min-height:46px;padding:0 17px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-weight:700}.primary{background:#f59e0b;color:#111827}.secondary{border:1px solid #e7eaf0;color:#111827}
      </style>
    </head>
    <body>
      <main class="card">
        <img class="brand" src="/brand/affiliey-logo-dark.png" alt="AFFILIEY">
        <div class="eyebrow">Ambiente do aluno</div>
        <h1>Não foi possível carregar seu painel agora.</h1>
        <p>O AFFILIEY registrou o erro para diagnóstico. Tente novamente em instantes ou volte à página inicial.</p>
        <div class="reference">Código para localizar o erro no log do servidor: <strong><?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div class="actions"><a class="button primary" href="/">Ir para AFFILIEY</a><a class="button secondary" href="/student-dashboard.php">Tentar novamente</a></div>
      </main>
    </body>
    </html>
    <?php
}
