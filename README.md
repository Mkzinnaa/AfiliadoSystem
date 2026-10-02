# Vértice — plataforma de afiliados

Aplicação PHP para gestão de espaços de produtores, afiliados, vendas, campanhas, equipe e integração de pedidos via webhook da Kiwify.

## Requisitos

- PHP 8.1 ou superior;
- extensões `PDO` e `openssl` habilitadas; `pdo_sqlite` para desenvolvimento local e `pdo_mysql` para MySQL no cPanel;
- Apache com `mod_rewrite` e suporte a `.htaccess` (para o fallback de raiz);
- diretório gravável pelo PHP para `/.runtime/app-data/`.

O desenvolvimento local continua usando SQLite por padrão. Em hospedagem, configure o driver MySQL. Crie um banco vazio; o sistema instala as tabelas na primeira requisição. Os dados do SQLite local não são migrados automaticamente.

## Rodar localmente

O runtime PHP baixado para desenvolvimento fica em `.runtime/` e não é enviado ao Git. Com PHP instalado no computador:

```sh
php -S 127.0.0.1:8000 -t public
```

Abra `http://127.0.0.1:8000`. Em localhost o espaço de demonstração é criado automaticamente, com login `mariana@novavida.com` e senha `Vertice2026!`. Essa demonstração serve somente para desenvolvimento local.

## Preparar o GitHub

O repositório exclui `.runtime/`, banco SQLite, logs e arquivos privados de storage. Não adicione credenciais, chaves de webhook, banco local ou dados reais ao Git.

Depois de criar um repositório vazio no GitHub, configure o remoto e publique a branch principal:

```sh
git add .
git commit -m "Preparar plataforma Vértice para hospedagem"
git branch -M main
git remote add origin https://github.com/SEU-USUARIO/SEU-REPOSITORIO.git
git push -u origin main
```

Substitua a URL de exemplo pela URL do repositório criado. Se já existir um remoto, confira `git remote -v` antes de adicionar outro.

## Publicar no cPanel

1. No cPanel, abra **MySQL Database Wizard** e crie um banco e um usuário com senha própria. Conceda ao usuário os privilégios necessários nesse banco. Não carregue o `.runtime/app-data/app.sqlite` do desenvolvimento.
2. Clone o repositório com **Git Version Control** do cPanel ou envie o snapshot do repositório para uma pasta privada fora de `public_html`, por exemplo `~/vertice`.
3. Aponte o domínio/subdomínio para `~/vertice/public`. Assim `modules/`, `storage/` e `.runtime/` ficam fora da raiz pública. Se o plano não permitir escolher a raiz do domínio, a regra na raiz do repositório encaminha as requisições para `public/`; valide que o Apache permite `mod_rewrite` e `.htaccess`.
4. Selecione PHP 8.1+ e habilite `pdo_mysql` e `openssl` no seletor de extensões do cPanel. (Localmente, `pdo_sqlite` continua sendo usado.)
5. Crie `~/vertice/.runtime/app-data/database.php`, fora da raiz pública, com os dados do banco criados no cPanel:

   ```php
   <?php
   return [
       'driver' => 'mysql',
       'host' => 'localhost',
       'port' => '3306',
       'database' => 'PREFIXO_nome_do_banco',
       'username' => 'PREFIXO_usuario',
       'password' => 'SENHA_DO_USUARIO_MYSQL',
       'charset' => 'utf8mb4',
   ];
   ```

   Troque os valores de exemplo pelos dados mostrados pelo cPanel. Restrinja o acesso ao arquivo e nunca o envie ao GitHub. As variáveis `VERTICE_DB_*` também podem ser usadas como alternativa.
6. Garanta que o usuário PHP consiga criar e escrever em `~/vertice/.runtime/app-data` (para a configuração privada e a chave de criptografia das integrações). Não use permissões `777`; ajuste proprietário/grupo/permissões conforme o provedor.
7. Configure HTTPS e force o domínio a usar HTTPS antes de compartilhar logins ou receber webhooks.
8. Desabilite o usuário demo antes da primeira requisição pública, definindo `VERTICE_DEMO_ENABLED=0` no ambiente PHP do domínio. O padrão já habilita o demo apenas em `localhost`; use um banco vazio para produção.
9. Gere uma chave aleatória privada de pelo menos 32 caracteres. Configure `VERTICE_SETUP_KEY` no ambiente PHP ou crie `~/vertice/.runtime/app-data/setup-key.php` com `<?php return 'COLOQUE_A_CHAVE_AQUI';`. Acesse `/platform-admin-setup.php` para criar o administrador comercial (senha exclusiva com pelo menos 14 caracteres). Depois, remova a variável ou apague o arquivo `setup-key.php`. Sem a chave, a tela de setup permanece indisponível. Proteja também a rota por IP ou privacidade de diretório durante a configuração, se possível.
10. Crie a conta inicial do produtor em `/register.php`: informe seu nome, o nome da empresa, seu e-mail e defina uma senha forte. Essa conta será proprietária do novo espaço; a senha é escolhida na tela e não fica embutida no projeto. O administrador comercial é um acesso separado.
11. Configure a integração da Kiwify dentro do espaço; o webhook precisa estar acessível por HTTPS. Faça backups do MySQL pelo cPanel e preserve também `.runtime/app-data/webhook.key`, necessária para descriptografar os segredos de integração cadastrados.

## Estrutura

- `public/`: páginas PHP, CSS, JavaScript e arquivos servidos ao navegador;
- `modules/`: autenticação, banco e regras da aplicação, fora da raiz pública recomendada;
- `storage/`: arquivos legados/privados, protegidos por `.htaccess`;
- `.runtime/app-data/`: configuração privada do MySQL, SQLite local e chave de integração; ignorados pelo Git.

## Notas antes de produção

O projeto está preparado para publicação, mas ainda é um MVP. Antes de abrir cadastro para clientes, revise termos e privacidade, recuperação real de senha/e-mail, limites e proteção contra abuso de cadastro, monitoramento, rotina testada de backup/restauração e plano de cobrança SaaS. Se o provedor cPanel não oferecer configuração de variáveis de ambiente PHP, proteja temporariamente a rota de setup com privacidade de diretório ou restrição de IP no Apache e remova essa proteção somente durante a configuração controlada.
