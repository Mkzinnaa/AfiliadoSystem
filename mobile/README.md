# App móvel do AFFILIEY

Este diretório contém os projetos Android e iOS gerados pelo Capacitor. O app abre o sistema hospedado em `https://afiliados.horizoncafe.com.br`; login e dados continuam no servidor PHP/MySQL. O diretório `www/` contém apenas a tela de abertura e a mensagem local de indisponibilidade de rede.

A API móvel fica em `https://afiliados.horizoncafe.com.br/api/v1/`. Para telas nativas futuras, autentique com `/auth/login.php`, envie o token como `Authorization: Bearer ...` e guarde-o em armazenamento seguro do sistema operacional, nunca em texto puro ou `localStorage`.

## Requisitos

- Node.js 22 ou superior e pnpm;
- Android: Android Studio, Android SDK e JDK compatível com a versão do Gradle gerada;
- iOS: macOS com Xcode. O projeto iOS pode ser gerado no Windows, mas compilação, assinatura e publicação exigem macOS/Xcode.

## Instalar dependências e sincronizar

```sh
cd mobile
pnpm install
pnpm exec cap sync
```

Para abrir no Android Studio ou Xcode:

```sh
pnpm android
pnpm ios
```

O Android Studio pode gerar APK de depuração para instalação local e AAB assinado para a Google Play. No Xcode, configure a equipe de assinatura e o identificador antes de arquivar para a App Store.

## Desenvolvimento em servidor local

O padrão aponta para o domínio de produção. Para testar com um servidor local, defina a URL antes de sincronizar e mantenha `localhost` ou `127.0.0.1`:

```powershell
$env:CAPACITOR_SERVER_URL = 'http://192.168.0.10:8000'
pnpm exec cap sync
```

Em um celular físico, use o IP local do computador e mantenha ambos na mesma rede. A configuração valida o host da aplicação para evitar apontar o app de produção a um domínio arbitrário. Depois do desenvolvimento, sincronize novamente sem a variável para restaurar o domínio oficial.

## Antes de publicar nas lojas

O empacotamento nativo está iniciado, mas os binários ainda precisam ser compilados e assinados nos ambientes Android e macOS. Este primeiro pacote carrega o painel web remoto; sozinho, pode não oferecer funcionalidade nativa suficiente para aprovação nas lojas. Planeje recursos integrados ao celular, como notificações push de comunicados/metas, compartilhamento nativo de links de afiliado e deep links, além de política de privacidade, ícones finais e imagens de loja. A Apple exige funcionalidades e experiência além de um site reempacotado; a Google Play também rejeita apps com funcionalidade limitada.
