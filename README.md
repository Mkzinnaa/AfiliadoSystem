# Vértice — plataforma de afiliados

Aplicação PHP para gestão de espaços de produtores, afiliados, vendas, campanhas, equipe e integração de pedidos via webhook da Kiwify.

## Requisitos

- PHP 8.1 ou superior;
- extensões `PDO`, `pdo_sqlite` e `openssl` habilitadas;
- Apache com `mod_rewrite` e suporte a `.htaccess` (para o fallback de raiz);
- diretório gravável pelo PHP para `/.runtime/app-data/`.

O SQLite é adequado para este MVP em uma única instância de hospedagem. Planeje migrar para MySQL/PostgreSQL antes de operar com grande volume, múltiplas instâncias ou requisitos maiores de disponibilidade.

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

1. Crie o banco de deploy em uma cópia limpa do projeto; não carregue o `.runtime/app-data/app.sqlite` usado no desenvolvimento.
2. Clone o repositório com **Git Version Control** do cPanel ou envie o snapshot do repositório para uma pasta privada fora de `public_html`, por exemplo `~/vertice`.
3. Aponte o domínio/subdomínio para `~/vertice/public`. Assim `modules/`, `storage/` e `.runtime/` ficam fora da raiz pública. Se o plano não permitir escolher a raiz do domínio, a regra na raiz do repositório encaminha as requisições para `public/`; valide que o Apache permite `mod_rewrite` e `.htaccess`.
4. Selecione PHP 8.1+ e habilite `pdo_sqlite` e `openssl` no seletor de extensões do cPanel.
5. Garanta que o usuário PHP consiga criar e escrever em `~/vertice/.runtime/app-data`. Não use permissões `777`; ajuste proprietário/grupo/permissões conforme o provedor.
6. Configure HTTPS e force o domínio a usar HTTPS antes de compartilhar logins ou receber webhooks.
7. Desabilite o usuário demo antes da primeira requisição pública, definindo `VERTICE_DEMO_ENABLED=0` no ambiente PHP do domínio. Na hospedagem cPanel, o local para essa variável depende do provedor; confirme se ele disponibiliza variáveis de ambiente para aplicações PHP. O padrão é demo habilitada apenas em `localhost`.
8. Gere uma chave aleatória privada de pelo menos 32 caracteres, configure-a temporariamente como `VERTICE_SETUP_KEY` no ambiente PHP do domínio e acesse `/platform-admin-setup.php`. Informe a chave para criar o administrador comercial da plataforma com uma senha exclusiva (mínimo de 14 caracteres). Remova `VERTICE_SETUP_KEY` do ambiente depois da criação. Sem essa chave, a rota de configuração retorna indisponível; depois que o admin existir, a rota encaminha ao login.
9. Crie o primeiro espaço de produtor pela tela de cadastro. Configure a integração da Kiwify dentro do espaço; o endpoint de webhook precisa estar acessível publicamente por HTTPS.
10. Configure backups regulares do diretório privado `.runtime/app-data/`, incluindo `app.sqlite` e `webhook.key`. A chave é necessária para descriptografar os segredos de integração já cadastrados.

## Estrutura

- `public/`: páginas PHP, CSS, JavaScript e arquivos servidos ao navegador;
- `modules/`: autenticação, banco e regras da aplicação, fora da raiz pública recomendada;
- `storage/`: arquivos legados/privados, protegidos por `.htaccess`;
- `.runtime/app-data/`: SQLite e chave privada gerados em execução; ignorados pelo Git.

## Notas antes de produção

O projeto está preparado para publicação, mas ainda é um MVP. Antes de abrir cadastro para clientes, revise termos e privacidade, recuperação real de senha/e-mail, limites e proteção contra abuso de cadastro, monitoramento, rotina testada de backup/restauração e plano de cobrança SaaS. Se o provedor cPanel não oferecer configuração de variáveis de ambiente PHP, proteja temporariamente a rota de setup com privacidade de diretório ou restrição de IP no Apache e remova essa proteção somente durante a configuração controlada.
