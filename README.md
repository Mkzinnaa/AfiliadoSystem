# Vértice — plataforma de afiliados

Aplicação PHP para gestão de espaços de produtores, afiliados, vendas, campanhas, equipe e integração de pedidos via webhooks da Kiwify, Hotmart e Eduzz. **MySQL é o único banco suportado.**

## Requisitos

- PHP 8.1 ou superior;
- extensões `PDO`, `pdo_mysql` e `openssl`;
- MySQL/MariaDB vazio e usuário com permissões sobre o banco;
- Apache com `mod_rewrite` e suporte a `.htaccess`;
- diretório privado gravável pelo PHP para `/.runtime/app-data/`.

O sistema cria as tabelas automaticamente na primeira conexão ao banco. Não há fallback para SQLite nem migração automática de dados antigos.

## Desenvolvimento local

Configure o `pdo_mysql` no PHP local. O runtime PHP de desenvolvimento fica em `.runtime/` e não é enviado ao Git. Crie `.runtime/app-data/database.php`:

```php
<?php
return [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'nome_do_banco',
    'username' => 'usuario_mysql',
    'password' => 'senha_mysql',
    'charset' => 'utf8mb4',
];
```

O arquivo é privado e ignorado pelo Git. Com o MySQL local configurado, inicie o servidor:

```sh
php -S 127.0.0.1:8000 -t public
```

Em `localhost`, um espaço demo é criado automaticamente (`mariana@novavida.com` / `Vertice2026!`). Esse acesso serve apenas para desenvolvimento local.

## Publicação no cPanel

1. Em **MySQL Database Wizard**, crie o banco e um usuário, concedendo os privilégios necessários sobre esse banco.
2. Mantenha o repositório em uma pasta privada fora de `public_html`, por exemplo `~/vertice`, e aponte o domínio para `~/vertice/public`. Se não puder escolher a raiz do domínio, o `.htaccess` da raiz encaminha as requisições a `public/`, desde que Apache permita `mod_rewrite` e `.htaccess`.
3. Selecione PHP 8.1+ e habilite `pdo_mysql` e `openssl`.
4. Crie `~/vertice/.runtime/app-data/database.php` fora da raiz pública, preenchendo os valores com os dados apresentados pelo cPanel:

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

   Use o host fornecido pela hospedagem; pode ser diferente de `localhost`. Não salve essa configuração no repositório nem compartilhe a senha.
5. Para convites e recuperação de senha por e-mail, crie `~/vertice/.runtime/app-data/mail.php` com as configurações SMTP da caixa de e-mail do domínio:

   ```php
   <?php
   return [
       'app_url' => 'https://afiliados.seudominio.com.br',
       'host' => 'mail.seudominio.com.br',
       'port' => 587,
       'encryption' => 'tls', // ou 'ssl' com a porta 465
       'username' => 'nao-responda@seudominio.com.br',
       'password' => 'SENHA_DA_CAIXA_DE_EMAIL',
       'from_email' => 'nao-responda@seudominio.com.br',
       'from_name' => 'Vértice',
   ];
   ```

   Substitua os exemplos pelos dados exibidos pelo seu provedor de e-mail. `app_url` deve ser a URL HTTPS pública do sistema. O arquivo é privado e ignorado pelo Git.
6. Garanta que o usuário PHP possa criar arquivos em `~/vertice/.runtime/app-data`; não use permissão `777`.
7. Configure HTTPS. Para a configuração inicial do painel comercial, gere uma chave com pelo menos 32 caracteres e defina temporariamente `VERTICE_SETUP_KEY` no ambiente PHP ou grave-a em `~/vertice/.runtime/app-data/setup-key.php` como `<?php return 'CHAVE_LONGA_ALEATORIA';`. Acesse `/platform-admin-setup.php` e crie a senha exclusiva do administrador (mínimo de 14 caracteres). Depois, remova a variável ou apague o arquivo da chave.
8. Crie a conta inicial do produtor em `/register.php`, escolhendo a senha na própria tela. O administrador comercial é um acesso separado.
9. Configure a integração de vendas; webhooks externos exigem HTTPS. A Kiwify usa uma assinatura gerada pelo Vértice. Na Hotmart, configure o webhook na versão 2.0.0, informe o Hottok no Vértice e selecione eventos de compra aprovada, reembolso, chargeback e cancelamento. Cadastre no perfil do afiliado o `affiliate_code` da Hotmart para atribuir as vendas. Na Eduzz, configure uma chave de segurança no Console, informe essa mesma chave no Vértice e selecione eventos de fatura paga, reembolsada, chargeback e cancelada; a atribuição usa o e-mail do afiliado do evento. Faça backups do MySQL pelo cPanel e preserve `.runtime/app-data/webhook.key` para recuperar os segredos criptografados.

## Estrutura

- `public/`: páginas PHP, CSS, JavaScript e arquivos servidos ao navegador;
- `modules/`: conexão MySQL, autenticação e regras da aplicação;
- `storage/`: arquivos privados e legado;
- `.runtime/app-data/`: credenciais MySQL e chave de integração, ignoradas pelo Git.

Produtores e administradores podem copiar o link público de inscrição no módulo Afiliados. As inscrições são gravadas no espaço do produtor com status pendente; a aprovação libera o código individual e a recusa desativa a solicitação. O link **Gerenciar grupos** permite criar categorias, renomear grupos e direcionar campanhas; ao renomear, afiliados e campanhas existentes acompanham a mudança.

No módulo Metas, campanhas podem ser direcionadas a toda a equipe, a um grupo ou a um afiliado ativo. Em contas de produção, o progresso usa pedidos aprovados dentro das datas configuradas; o espaço demo mantém indicadores ilustrativos.

Campanhas também aceitam a métrica **Clientes novos**. Os webhooks guardam apenas um identificador HMAC do cliente (e-mail normalizado ou ID informado pelo checkout), nunca o e-mail em texto aberto. A contagem exige que a integração envie uma identidade consistente; pedidos históricos sem esse dado não entram nessa métrica.

## Notas de produção

O sistema ainda é um MVP. Antes de abrir cadastro para clientes, revise termos e privacidade, limites de cadastro, monitoramento, backups e cobrança SaaS. Convites de equipe e recuperação de senha enviam e-mails quando o SMTP está configurado. Não compartilhe credenciais ou segredos em mensagens, commits ou arquivos públicos.
