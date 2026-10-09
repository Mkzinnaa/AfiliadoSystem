# AFFILIEY — plataforma de afiliados

Aplicação PHP para gestão de espaços de produtores, afiliados, vendas, campanhas, equipe e integração de pedidos via webhooks da Kiwify, Hotmart e Eduzz. **MySQL é o único banco suportado.**

O painel também pode ser instalado no celular como PWA. No Android, use **Instalar app** ou **Adicionar à tela inicial** no navegador. No iPhone/iPad, abra em Safari, toque em **Compartilhar** e escolha **Adicionar à Tela de Início**. A instalação exige HTTPS (ou localhost no desenvolvimento). Telas autenticadas e dados do produtor continuam sendo carregados do servidor; offline, o app mostra apenas uma página informativa e não guarda vendas ou dados pessoais no cache. Os projetos nativos Android e iOS iniciados com Capacitor ficam em `mobile/`; gerar um APK exige Android Studio/SDK e JDK 21, e compilar/assinar o iOS exige macOS com Xcode. Antes da App Store, o app também precisa oferecer utilidade integrada além de somente abrir o painel web, conforme a diretriz de funcionalidade mínima da Apple ([App Review Guidelines 4.2](https://developer.apple.com/app-store/review/guidelines/)).

## Requisitos

- PHP 8.1 ou superior;
- extensões `PDO`, `pdo_mysql` e `openssl`;
- MySQL/MariaDB vazio e usuário com permissões sobre o banco;
- Apache com `mod_rewrite` e suporte a `.htaccess`;
- diretório privado gravável pelo PHP para `/.runtime/app-data/`.

O sistema cria as tabelas automaticamente na primeira conexão ao banco. Não há fallback para SQLite nem migração automática de dados antigos.

## API móvel

A API JSON fica separada da interface web em `/api/v1/` e usa autenticação Bearer independente de cookies de sessão. O login recebe `email`, `password` e, opcionalmente, `device_name`; devolve um token aleatório válido por 30 dias. O banco guarda somente o hash do token. Envie `Authorization: Bearer TOKEN` nas demais chamadas e use o endpoint de logout para revogá-lo. Use sempre HTTPS fora do desenvolvimento local.

Exemplo de login:

```http
POST /api/v1/auth/login.php
Content-Type: application/json

{"email":"voce@empresa.com","password":"sua-senha","device_name":"Meu celular"}
```

Endpoints atuais:

- `GET /api/v1/auth/me.php` — usuário e espaço autenticados;
- `POST /api/v1/auth/logout.php` — revoga o token atual;
- `GET /api/v1/dashboard.php` — indicadores principais e meta ativa;
- `GET /api/v1/affiliates.php` — afiliados, com filtros `q`, `status`, `limit` e `offset`;
- `GET /api/v1/sales.php` — pedidos, com filtros `status`, `limit` e `offset`;
- `GET /api/v1/campaigns.php` — metas e progresso por afiliado;
- `GET /api/v1/ranking.php?metric=revenue&start=AAAA-MM-DD&end=AAAA-MM-DD` — ranking por período;
- `GET /api/v1/rewards.php` e `GET /api/v1/announcements.php` — recompensas liberadas e comunicados.

Todas as respostas usam `{ "data": ..., "meta": ..., "error": ... }`. CORS aceita o domínio do AFFILIEY e as origens padrão do Capacitor; origens web adicionais podem ser incluídas em `VERTICE_API_ALLOWED_ORIGINS`, separadas por vírgulas. A primeira versão oferece autenticação e leitura dos módulos; operações de escrita pelo app podem ser adicionadas por endpoint conforme forem necessárias.

## Desenvolvimento local

Configure o `pdo_mysql` no PHP local. O runtime PHP de desenvolvimento fica em `.runtime/` e não é enviado ao Git. Para credenciais e configuração, copie `.env.example` para `.env` e preencha os valores locais. O `.env` real é ignorado pelo Git; o exemplo versionado não contém segredos. Variáveis definidas pelo servidor têm prioridade sobre o arquivo. A configuração privada legada `.runtime/app-data/database.php` continua aceita.

```sh
Copy-Item .env.example .env
```

Defina `VERTICE_DB_NAME`, `VERTICE_DB_USER` e `VERTICE_DB_PASSWORD` no `.env`. Deixe `VERTICE_COOKIE_SECURE` vazio para usar o servidor HTTP local. O acesso demo fica disponível por padrão apenas em localhost; `VERTICE_DEMO_ENABLED=false` pode desativá-lo.

Com o MySQL local configurado, inicie o servidor:

```sh
php -S 127.0.0.1:8000 -t public
```

Em `localhost`, um espaço demo é criado automaticamente (`123` / `123456789`) quando `VERTICE_DEMO_ENABLED` não está definido ou está ativado. Esse acesso serve apenas para desenvolvimento local.

## Publicação no cPanel

1. Em **MySQL Database Wizard**, crie o banco e um usuário, concedendo os privilégios necessários sobre esse banco.
2. Mantenha o repositório em uma pasta privada fora de `public_html`, por exemplo `~/vertice`, e aponte o domínio para `~/vertice/public`. Se não puder escolher a raiz do domínio, o `.htaccess` da raiz encaminha as requisições a `public/`, desde que Apache permita `mod_rewrite` e `.htaccess`.
3. Selecione PHP 8.1+ e habilite `pdo_mysql` e `openssl`.
4. Copie `~/vertice/.env.example` para `~/vertice/.env` fora da raiz pública e preencha as variáveis abaixo com os dados do cPanel. Restrinja o arquivo (`chmod 600 ~/vertice/.env`) e mantenha `VERTICE_DEMO_ENABLED=false` e `VERTICE_COOKIE_SECURE=true` em produção:

   ```dotenv
   VERTICE_DB_DRIVER=mysql
   VERTICE_DB_HOST=HOST_MYSQL_DO_CPANEL
   VERTICE_DB_PORT=3306
   VERTICE_DB_NAME=PREFIXO_nome_do_banco
   VERTICE_DB_USER=PREFIXO_usuario
   VERTICE_DB_PASSWORD=SENHA_DO_USUARIO_MYSQL
   VERTICE_DEMO_ENABLED=false
   VERTICE_COOKIE_SECURE=true
   VERTICE_APP_URL=https://afiliados.seudominio.com.br
   VERTICE_SETUP_KEY=CHAVE_ALEATORIA_COM_PELO_MENOS_32_CARACTERES
   ```

   Use o host fornecido pela hospedagem; pode ser diferente de `localhost`. Não salve o `.env` no repositório nem compartilhe seu conteúdo. O sistema ainda aceita os arquivos privados `.runtime/app-data/database.php` e `mail.php` durante a migração.
5. Para convites e recuperação de senha por e-mail, configure as variáveis SMTP no mesmo `.env`:

   ```dotenv
   VERTICE_SMTP_HOST=mail.seudominio.com.br
   VERTICE_SMTP_PORT=587
   VERTICE_SMTP_ENCRYPTION=tls
   VERTICE_SMTP_USERNAME=nao-responda@seudominio.com.br
   VERTICE_SMTP_PASSWORD=SENHA_DA_CAIXA_DE_EMAIL
   VERTICE_SMTP_FROM_EMAIL=nao-responda@seudominio.com.br
   VERTICE_SMTP_FROM_NAME=AFFILIEY
   ```

   Substitua os exemplos pelos dados exibidos pelo seu provedor. `VERTICE_APP_URL` deve ser a URL HTTPS pública do sistema. Nunca publique o `.env`.
   O módulo **Comunicados** envia mensagens aos afiliados ativos de forma assíncrona. Para processar a fila, crie no Cron Jobs do cPanel uma tarefa para executar a cada minuto (ajuste o caminho do PHP e do repositório à hospedagem):

   ```sh
   /usr/local/bin/php /home/USUARIO/vertice/scripts/process-announcements.php
   ```

## Proteção de dados e chaves

Senhas de usuários e tokens de acesso da API são armazenados como hashes. Segredos recuperáveis das integrações usam AES-256-GCM com chaves derivadas separadamente para criptografia; o formato versionado novo continua lendo os segredos criptografados no formato anterior. A chave raiz fica em `.runtime/app-data/webhook.key`, fora da pasta pública; preserve-a em backup privado criptografado junto com o banco, ou as credenciais de integração não poderão ser recuperadas. O sistema restringe essa chave a `0600` e a pasta privada a `0700` em sistemas Unix.

E-mails de contas e afiliados são necessários para login e comunicação e permanecem legíveis no banco. Proteja o MySQL e os backups no provedor. O código desativa a exibição de erros e registra os detalhes apenas nos logs do servidor. O login web e os painéis administrativos bloqueiam tentativas repetidas; redefinir uma senha revoga os tokens da API daquele usuário.

   O script processa até 20 destinatários por execução e tenta novamente falhas até três vezes. O caminho do PHP pode ser diferente no servidor; confirme-o com a hospedagem. O SMTP precisa estar configurado para os comunicados serem enviados.
6. Garanta que o usuário PHP possa criar arquivos em `~/vertice/.runtime/app-data`; não use permissão `777`.
7. Configure HTTPS. Para a configuração inicial do painel comercial, gere uma chave com pelo menos 32 caracteres e defina temporariamente `VERTICE_SETUP_KEY` no ambiente PHP ou grave-a em `~/vertice/.runtime/app-data/setup-key.php` como `<?php return 'CHAVE_LONGA_ALEATORIA';`. Acesse `/platform-admin-setup.php` e crie a senha exclusiva do administrador (mínimo de 14 caracteres). Depois, remova a variável ou apague o arquivo da chave.
8. Crie a conta inicial do produtor em `/register.php`, escolhendo a senha na própria tela. O administrador comercial é um acesso separado.
9. Configure a integração de vendas; webhooks externos exigem HTTPS. A Kiwify usa uma assinatura gerada pelo AFFILIEY. Na Hotmart, configure o webhook na versão 2.0.0, informe o Hottok no AFFILIEY e selecione eventos de compra aprovada, reembolso, chargeback e cancelamento. Cadastre no perfil do afiliado o `affiliate_code` da Hotmart para atribuir as vendas. Na Eduzz, configure uma chave de segurança no Console, informe essa mesma chave no AFFILIEY e selecione eventos de fatura paga, reembolsada, chargeback e cancelada; a atribuição usa o e-mail do afiliado do evento. Faça backups do MySQL pelo cPanel e preserve `.runtime/app-data/webhook.key` para recuperar os segredos criptografados.

## Estrutura

- `public/`: páginas PHP, CSS, JavaScript e arquivos servidos ao navegador;
- `modules/`: conexão MySQL, autenticação e regras da aplicação;
- `storage/`: arquivos privados e legado;
- `.runtime/app-data/`: credenciais MySQL e chave de integração, ignoradas pelo Git.

Produtores e administradores podem copiar o link público de inscrição no módulo Afiliados. As inscrições são gravadas no espaço do produtor com status pendente; a aprovação libera o código individual e a recusa desativa a solicitação. O link **Gerenciar grupos** permite criar categorias, renomear grupos e direcionar campanhas; ao renomear, afiliados, campanhas e regras de recompensa existentes acompanham a mudança.

No módulo Metas, campanhas podem ser direcionadas a toda a equipe, a um grupo ou a um afiliado ativo. Em contas de produção, o progresso usa pedidos aprovados dentro das datas configuradas; o espaço demo mantém indicadores ilustrativos. O módulo **Ranking** permite comparar faturamento, vendas, clientes novos, conversão e crescimento por período e grupo. A métrica crescimento compara faturamento ao período anterior equivalente. Em **Recompensas**, produtores podem cadastrar benefícios por faturamento, vendas ou novos clientes, com regra semanal, mensal ou acumulada, válida para um grupo ou para todos. Vendas integradas desbloqueiam as recompensas; o produtor registra manualmente a entrega ou o pagamento. Reembolsos podem remover recompensas ainda não entregues se a meta deixar de ser atingida.

No módulo **Comunicados**, produtores e administradores podem enviar e-mails para afiliados ativos, um grupo ou uma pessoa e acompanhar a fila e os resultados. O envio depende do SMTP e da tarefa Cron descrita acima; sem o Cron, os comunicados ficam aguardando processamento.

Campanhas também aceitam a métrica **Clientes novos**. Os webhooks guardam apenas um identificador HMAC do cliente (e-mail normalizado ou ID informado pelo checkout), nunca o e-mail em texto aberto. A contagem exige que a integração envie uma identidade consistente; pedidos históricos sem esse dado não entram nessa métrica.

Na tela Afiliados, configure a página HTTPS de vendas do produto. Os links de divulgação passam por `go.php`, registram visitantes únicos por afiliado com um cookie aleatório e depois redirecionam para esse destino. A conversão exibida é estimada por pedidos aprovados atribuídos ao afiliado divididos por visitantes únicos; não é uma associação individual de cada clique ao pedido.

## Notas de produção

O sistema ainda é um MVP. Antes de abrir cadastro para clientes, revise termos e privacidade, limites de cadastro, monitoramento, backups e cobrança SaaS. Convites de equipe e recuperação de senha enviam e-mails quando o SMTP está configurado. Não compartilhe credenciais ou segredos em mensagens, commits ou arquivos públicos.
