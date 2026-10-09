<?php
declare(strict_types=1);
require_once __DIR__ . '/../modules/auth.php';
start_app_session();
$user = current_user();
$activeProfile = (string)($user['active_profile'] ?? 'producer');
$panelUrl = $activeProfile === 'affiliate'
    ? 'student-dashboard.php'
    : (empty($user['tenant_id']) ? 'workspace-create.php' : 'dashboard.php');
$firstName = trim(explode(' ', (string)($user['name'] ?? ''))[0] ?? '');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#111827">
  <meta name="description" content="AFFILIEY — sua plataforma de gestão de afiliados, comunidades e vendas.">
  <title>AFFILIEY — Plataforma de Afiliados</title>
  <link rel="icon" type="image/png" href="brand/affiliey-favicon.png">
  <style>
    :root{color-scheme:light;--gold:#f59e0b;--gold-dark:#d97706;--ink:#111827;--muted:#667085;--line:#e7eaf0;--surface:#fff;--soft:#f7f8fa}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--soft);color:var(--ink);font:16px/1.55 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
    .top{height:76px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,6vw,88px)}
    .brand img{display:block;width:174px;height:auto}.top a{color:var(--ink);font-weight:650;text-decoration:none}.top a:hover{color:var(--gold-dark)}
    main{width:min(1160px,calc(100% - 40px));margin:0 auto;padding:clamp(56px,10vh,112px) 0 72px;display:grid;grid-template-columns:1.15fr .85fr;gap:clamp(36px,7vw,100px);align-items:center}
    .eyebrow{display:inline-flex;align-items:center;gap:9px;color:#9a5b00;font-size:13px;font-weight:750;letter-spacing:.1em;text-transform:uppercase}.dot{width:9px;height:9px;border-radius:50%;background:var(--gold);box-shadow:0 0 0 5px #f59e0b20}
    h1{font-size:clamp(40px,6vw,68px);line-height:1.04;letter-spacing:-.055em;margin:22px 0 18px;max-width:680px}h1 span{color:var(--gold-dark)}.lead{font-size:18px;color:var(--muted);max-width:560px;margin:0 0 32px}
    .actions{display:flex;flex-wrap:wrap;gap:12px}.button{min-height:50px;padding:0 21px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-weight:700;transition:transform .2s,background .2s,border-color .2s}.button:hover{transform:translateY(-2px)}.primary{background:var(--gold);color:var(--ink)}.primary:hover{background:var(--gold-dark)}.secondary{background:#fff;color:var(--ink);border:1px solid var(--line)}.secondary:hover{border-color:#c4cad4}
    .panel{background:var(--ink);color:#fff;border-radius:24px;padding:30px;box-shadow:0 24px 70px #11182720;position:relative;overflow:hidden}.panel:before{content:"";position:absolute;width:240px;height:240px;border-radius:50%;background:#f59e0b20;filter:blur(3px);right:-100px;top:-120px}.panel-head{display:flex;align-items:center;gap:14px;position:relative}.symbol{width:46px;height:46px;border-radius:14px;background:#fff;display:grid;place-items:center;overflow:hidden}.symbol img{width:42px;height:42px;object-fit:contain}.panel h2{font-size:17px;margin:0}.panel small{color:#aeb7c6}.metric{margin-top:30px;padding:19px;border:1px solid #ffffff18;border-radius:15px;background:#ffffff08}.metric-label{font-size:13px;color:#bac3d0}.metric-value{font-weight:750;font-size:24px;margin-top:5px}.progress{height:7px;border-radius:99px;background:#ffffff20;margin-top:18px;overflow:hidden}.progress i{display:block;height:100%;width:72%;border-radius:inherit;background:linear-gradient(90deg,var(--gold),var(--gold-dark))}.note{font-size:13px;color:#bac3d0;margin:16px 0 0}
    footer{width:min(1160px,calc(100% - 40px));margin:0 auto;padding:20px 0 30px;color:#8992a1;font-size:13px;border-top:1px solid var(--line)}
    @media(max-width:760px){.top{height:66px;padding:0 20px}.brand img{width:148px}main{grid-template-columns:1fr;padding-top:58px;gap:40px}.panel{padding:24px}h1{font-size:46px}.lead{font-size:16px}}
    @media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
  </style>
</head>
<body>
  <header class="top">
    <a class="brand" href="/" aria-label="AFFILIEY — início"><img src="brand/affiliey-logo-dark.png" alt="AFFILIEY"></a>
    <?php if ($user !== null): ?>
      <a href="<?= htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8') ?>">Abrir meu painel&nbsp; ↗</a>
    <?php else: ?>
      <a href="login.php">Entrar</a>
    <?php endif; ?>
  </header>
  <main>
    <section>
      <div class="eyebrow"><span class="dot"></span> Plataforma de afiliados</div>
      <h1><?= $user !== null && $firstName !== '' ? 'Olá, ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '.<br>' : '' ?>Cresça com <span>conexões</span> que vendem.</h1>
      <p class="lead">Gerencie afiliados, acompanhe resultados e fortaleça sua comunidade em um só lugar — com a AFFILIEY.</p>
      <div class="actions">
        <?php if ($user !== null): ?>
          <a class="button primary" href="<?= htmlspecialchars($panelUrl, ENT_QUOTES, 'UTF-8') ?>">Abrir meu painel <span aria-hidden="true">&nbsp;→</span></a>
          <a class="button secondary" href="login.php?logout=1">Sair da conta</a>
        <?php else: ?>
          <a class="button primary" href="login.php">Acessar plataforma <span aria-hidden="true">&nbsp;→</span></a>
          <a class="button secondary" href="register.php">Criar minha conta</a>
        <?php endif; ?>
      </div>
    </section>
    <aside class="panel" aria-label="Apresentação da AFFILIEY">
      <div class="panel-head"><span class="symbol"><img src="brand/affiliey-symbol.png" alt=""></span><div><h2>Seu negócio, conectado.</h2><small>Um espaço para crescer junto.</small></div></div>
      <div class="metric"><div class="metric-label">Uma plataforma. Mais possibilidades.</div><div class="metric-value">Produtores + Afiliados</div><div class="progress" aria-hidden="true"><i></i></div></div>
      <p class="note">Entre para acompanhar seu ambiente e continuar de onde parou.</p>
    </aside>
  </main>
  <footer>© <?= date('Y') ?> AFFILIEY. Plataforma de gestão e crescimento.</footer>
</body>
</html>
