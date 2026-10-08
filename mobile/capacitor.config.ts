import type { CapacitorConfig } from '@capacitor/cli';

const appUrl = process.env.CAPACITOR_SERVER_URL || 'https://afiliados.horizoncafe.com.br';
const parsedUrl = new URL(appUrl);
const permittedHost = 'afiliados.horizoncafe.com.br';
const ipv4 = parsedUrl.hostname.split('.').map(Number);
const isPrivateIpv4 = ipv4.length === 4 && ipv4.every((part) => Number.isInteger(part) && part >= 0 && part <= 255)
  && (ipv4[0] === 10 || (ipv4[0] === 172 && ipv4[1] >= 16 && ipv4[1] <= 31) || (ipv4[0] === 192 && ipv4[1] === 168));
const isLocalDevelopment = ['localhost', '127.0.0.1', '::1'].includes(parsedUrl.hostname) || isPrivateIpv4;

if (parsedUrl.username || parsedUrl.password || parsedUrl.search || parsedUrl.hash || parsedUrl.pathname !== '/') {
  throw new Error('CAPACITOR_SERVER_URL deve conter somente a origem do servidor, sem caminho, credenciais ou parâmetros.');
}

if (parsedUrl.protocol !== 'https:' && !isLocalDevelopment) {
  throw new Error('O app móvel da AFFILIEY só pode carregar o sistema por HTTPS.');
}

if (parsedUrl.hostname !== permittedHost && !isLocalDevelopment) {
  throw new Error(`Host não permitido na configuração do app: ${parsedUrl.hostname}`);
}

if (!isLocalDevelopment && parsedUrl.port && parsedUrl.port !== '443') {
  throw new Error('A AFFILIEY em produção deve usar a porta HTTPS padrão.');
}

const config: CapacitorConfig = {
  appId: 'com.horizoncafe.vertice',
  appName: 'AFFILIEY',
  webDir: 'www',
  server: {
    url: parsedUrl.origin,
    cleartext: isLocalDevelopment,
    allowNavigation: [permittedHost, ...(isLocalDevelopment ? [parsedUrl.host] : [])],
    errorPath: 'offline.html',
  },
};

export default config;
