# Coletor de Leads (QR code) — LGLA 2026

App web mobile para a equipe capturar leads apontando a câmera do celular para o QR
code do crachá de cada pessoa no evento. Funciona como site próprio, hospedado no
seu GoDaddy (cPanel), com PHP + MySQL — sem depender de Google Sheets / Apps Script.

## Como funciona

1. Cada pessoa da equipe (captador) abre o link no celular.
2. Escaneia o **próprio** QR code do crachá (isso identifica quem é o captador).
3. Preenche **nome** e **empresa** uma única vez e toca em "Começar a capturar".
4. A partir daí, o app fica logado no celular (não pede login de novo). Basta tocar
   no botão de câmera sempre que quiser capturar um novo lead.
5. A cada captura: toca um bipe, pisca um feedback verde (novo) ou laranja (repetido),
   e a linha é gravada no banco compartilhado por todos os captadores.
6. O painel mostra: leads únicos, leads repetidos, e um gráfico de barras com as
   capturas dos dois dias do evento (11 e 12/08), cada barra dividida em únicos/repetidos.
7. Você (organizador) baixa todos os dados em CSV/Excel a qualquer momento, via um
   link protegido por senha — sem interface visual, só o download.

Um lead é "repetido" quando **o mesmo captador** escaneia o mesmo QR code mais de
uma vez. Se dois captadores diferentes escanearem o mesmo QR, cada um conta como
único na própria conta (o cruzamento de quem pegou o quê é feito depois, por você,
a partir do CSV).

## Requisito importante: HTTPS

A câmera do celular (`getUserMedia`) só funciona em conexão segura (HTTPS). No
cPanel do GoDaddy, ative o **SSL grátis** (Let's Encrypt) do domínio antes de
divulgar o link para a equipe. Sem HTTPS, a câmera não abre em nenhum celular.

## Passo a passo de instalação (cPanel GoDaddy)

### 1. Criar o banco de dados MySQL
- No cPanel, vá em **MySQL Databases**.
- Crie um banco (ex: `leadcoletor`) e um usuário com senha forte.
- Associe o usuário ao banco com todas as permissões.
- Anote: nome do banco, usuário e senha (o cPanel geralmente prefixa tudo com
  `seuusuario_`, ex: `viexame_leadcoletor`).

### 2. Importar o schema
- No cPanel, abra **phpMyAdmin**, selecione o banco criado.
- Vá em **Importar** e envie o arquivo [`schema.sql`](schema.sql) deste projeto.
- Isso cria as tabelas `capturadores` e `capturas`.

### 3. Configurar o `config.php`
Abra [`config.php`](config.php) e preencha com os dados reais:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'seuusuario_leadcoletor');
define('DB_USER', 'seuusuario_leadcoletor');
define('DB_PASS', 'sua_senha_forte_aqui');

define('EVENT_DAY_1', '2026-08-11');
define('EVENT_DAY_2', '2026-08-12');

define('ADMIN_EXPORT_KEY', 'escolha-uma-senha-longa-e-dificil-de-adivinhar');
```

`ADMIN_EXPORT_KEY` é a "senha" que só você vai usar para baixar os dados — troque
pelo um valor único, não deixe o padrão do arquivo.

### 4. Subir os arquivos
Envie **toda a pasta** deste projeto (via File Manager do cPanel ou FTP) para o
diretório do seu site — ex: `public_html/` (raiz do domínio) ou uma subpasta como
`public_html/leads/` se preferir um link tipo `seudominio.com/leads/`.

Estrutura esperada no servidor:
```
public_html/leads/
├── index.html
├── config.php
├── schema.sql (não precisa ficar no servidor, só usado no import)
├── api/
│   ├── _common.php
│   ├── register.php
│   ├── session.php
│   └── capture.php
├── admin/
│   └── export.php
└── assets/
    ├── app.js
    └── style.css
```

### 5. Testar
- Acesse `https://seudominio.com/leads/` pelo celular.
- Autorize o acesso à câmera quando o navegador pedir.
- Escaneie um QR code de teste, preencha nome/empresa e capture um segundo QR
  para simular um lead.
- Confirme no phpMyAdmin que as tabelas `capturadores` e `capturas` receberam os
  dados.

### 6. Baixar os dados coletados (você, organizador)
A qualquer momento, acesse:
```
https://seudominio.com/leads/admin/export.php?key=SUA_SENHA_DO_ADMIN_EXPORT_KEY
```
Isso baixa um `.csv` (abre direto no Excel) com todas as capturas de todos os
captadores, incluindo nome/empresa de quem capturou e o QR code do lead — pronto
para cruzar com a lista de inscritos do evento depois.

## Notas técnicas
- Leitura de QR: usa a API nativa `BarcodeDetector` quando o navegador suporta
  (Chrome/Android), e cai para a biblioteca `jsQR` (via CDN) em navegadores que não
  suportam (ex: Safari/iOS mais antigos).
- "Não deslogar": os dados de sessão (token, nome, empresa) ficam salvos no
  `localStorage` do navegador daquele celular. Se o captador limpar os dados do
  navegador ou trocar de celular, ele escaneia o próprio QR de novo — o sistema
  reconhece o QR já cadastrado e retoma o histórico dele automaticamente (não
  duplica capturador).
- Sem Google Sheets / Apps Script: tudo grava direto em MySQL, dentro da sua
  própria hospedagem.
