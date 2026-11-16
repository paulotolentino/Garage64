# Garage64

Plataforma web para colecionadores de miniaturas diecast (1:64 e outras escalas):
cada colecionador mantém sua **garagem** privada, publica sua **coleção** em um
endereço próprio (`/u/{slug}`) e interage com a comunidade — seguir, comentar,
curtir e avaliar miniaturas.

## Stack

- **Backend:** PHP 8+ (sem framework), PDO com prepared statements
- **Banco de dados:** MySQL 8+ / MariaDB
- **Frontend:** HTML5, Bootstrap 5, Vanilla JS, Font Awesome
- **Imagens:** conversão automática para WebP + geração de thumbnails (GD)
- **Servidor:** Apache com `.htaccess` (URLs limpas); compatível com mod_php e PHP-FPM

## Funcionalidades

### Coleção e garagem
- Galeria pública por colecionador (`/u/{slug}`) com busca (FULLTEXT) e filtros
- Página de detalhes de cada miniatura, com galeria de fotos e lightbox
- Garagem privada (dashboard) com estatísticas da coleção
- CRUD completo de miniaturas: upload de fotos, tags, categoria, escala,
  condição, localização, avaliação emocional e dados financeiros
- Modo público / privado por miniatura (dados sensíveis só para o dono)
- Wishlist com conversão para a coleção
- Gerenciamento de categorias e tags
- Exportação da coleção

### Social / comunidade
- Cadastro de novos colecionadores com slug público reservado/validado
- Perfis públicos com avatar e bio
- Seguir / deixar de seguir colecionadores (`/u/{slug}/seguidores`, `/seguindo`)
- Mural da comunidade (feed de novas miniaturas, comentários e follows)
- Comentários e respostas (thread de um nível) com menções `@slug` e fixação
- Curtidas em miniaturas (contador exibido nos cards)
- Avaliação pública por estrelas (dedupe por IP)
- Notificações de interações

### Administração
- Painel administrativo por colecionador (isolamento por `user_id`)
- Superadmin com tela de **Manutenção** (migrações idempotentes via navegador)
- Migração de imagens legadas para WebP

## Segurança

- Senhas com `password_hash` (bcrypt)
- CSRF em todas as ações de escrita (`hash_equals`)
- Ownership enforce em todo o CRUD (sem acesso entre colecionadores)
- Uploads validados por MIME real (`finfo`), limite de tamanho e guarda
  anti-decompression-bomb; re-encode para WebP (descarta metadados)
- Execução de scripts bloqueada dentro de `uploads/`
- Rate limiting (janela fixa, em banco) para login, cadastro, comentários e avaliações
- IP real do visitante atrás da Cloudflare (`CF-Connecting-IP` validado contra os
  ranges oficiais; anti-spoofing — nunca confia em `X-Forwarded-For`)
- Cookie de sessão endurecido (`HttpOnly`, `SameSite=Lax`, `Secure` sob HTTPS)
- `display_errors` desligado em produção (detecção automática de ambiente)
- Headers de segurança e bloqueio de arquivos sensíveis via `.htaccess`
- Instalador bloqueado após uso (`installed.lock` → 403)

## Requisitos

- PHP **8.0+** com extensões: `pdo`, `pdo_mysql`, `mbstring`, `fileinfo`, `gd`
  (recomendado `exif` para orientação correta de fotos)
- MySQL 8+ / MariaDB
- Apache com `mod_rewrite` (e idealmente `mod_headers`, `mod_expires`, `mod_deflate`)

## Instalação

### Opção A — Instalador via navegador (recomendado, sem acesso à linha de comando)

1. **Faça o upload** de todos os arquivos do projeto para o servidor via painel de hospedagem (gerenciador de arquivos, FTP, Git deploy, etc.).
2. **Garanta as permissões de escrita** nas pastas `uploads/` e `includes/` (chmod 755 ou equivalente no painel).
3. **Acesse o instalador** no seu navegador:
   ```
   https://seusite.com/install.php
   ```
4. O instalador irá:
   - Verificar automaticamente os requisitos (PHP, extensões, permissões).
   - Exibir um formulário para preencher as credenciais do banco de dados, URL do site e usuário admin.
   - Criar o banco de dados e executar o schema.
   - Gerar `includes/config.local.php` com suas configurações.
   - Criar o arquivo `installed.lock`, bloqueando o acesso ao instalador permanentemente.
5. Após a conclusão, acesse `/` (galeria pública) ou `/admin/` (painel administrativo).

> **Segurança:** assim que `installed.lock` existir, qualquer acesso a `/install.php` retorna 403.
> Você pode também excluir `install.php` do servidor após a instalação.

---

### Opção B — Instalação via linha de comando

#### 1. Banco de dados

```bash
mysql -u root -p < database/schema.sql
```

#### 2. Configuração

Copie e edite o arquivo de configuração:

```bash
cp includes/config.php includes/config.local.php
# Edite includes/config.local.php com suas credenciais (DB, APP_URL, etc.)
```

#### 3. Criar o usuário admin

```bash
php setup.php
```

#### 4. Permissões

```bash
chmod 755 uploads/
```

#### 5. Servidor web

Configure seu Apache para apontar para o diretório raiz do projeto com suporte a `.htaccess` (AllowOverride All).

> **Atualizando uma instalação existente:** entre como superadmin e acesse
> `/admin/manutencao` para aplicar migrações de tabelas, colunas e índices de
> forma idempotente (sem rodar SQL manualmente).

## Configuração atrás da Cloudflare

O sistema resolve o IP real via `CF-Connecting-IP` apenas quando a conexão vem de
um range oficial da Cloudflare. Como camada de infraestrutura (recomendada), você
pode configurar `mod_remoteip` com os ranges da Cloudflare para que `REMOTE_ADDR`
já reflita o IP real em toda a aplicação.

## Rotas (URLs limpas)

| Rota | Destino |
| --- | --- |
| `/` | Landing / descoberta |
| `/u/{slug}` | Coleção pública do colecionador |
| `/u/{slug}/seguidores`, `/u/{slug}/seguindo` | Listas de follows |
| `/mini/{id}` ou `/mini/{id}/{slug}` | Detalhe da miniatura |
| `/collections` | Lista de colecionadores |
| `/community` | Mural da comunidade |
| `/register` | Cadastro |
| `/admin/` | Painel administrativo |
| `/robots.txt`, `/sitemap.xml` | Gerados via PHP |

## Estrutura

```
├── index.php               # Landing / descoberta
├── collection.php          # Coleção pública (/u/{slug})
├── collections.php         # Lista de colecionadores
├── community.php           # Mural da comunidade
├── miniature.php           # Detalhe da miniatura (/mini/{id})
├── follows.php             # Seguidores / seguindo
├── register.php            # Cadastro de colecionador
├── robots.php / sitemap.php # robots.txt e sitemap.xml dinâmicos
├── 404.php                 # Página de erro
├── install.php             # Instalador via navegador (bloqueado após uso)
├── setup.php               # Configuração inicial do admin (CLI)
├── installed.lock          # Criado pelo instalador; bloqueia /install.php
├── admin/
│   ├── index.php           # Dashboard / garagem privada
│   ├── login.php / logout.php
│   ├── miniatures.php      # CRUD de miniaturas
│   ├── wishlist.php
│   ├── categories.php / tags.php
│   ├── profile.php         # Perfil do colecionador
│   ├── notifications.php
│   ├── users.php           # Gestão de usuários (superadmin)
│   ├── manutencao.php      # Migrações via navegador (superadmin)
│   ├── migrate_webp.php    # Migração de imagens para WebP
│   └── export.php          # Exportação da coleção
├── includes/
│   ├── config.php          # Configurações + política de erros
│   ├── db.php              # Conexão PDO
│   ├── auth.php            # Autenticação, CSRF, slugs, rate limit, client_ip()
│   ├── functions.php       # Funções de domínio (queries, upload, social)
│   └── header_*.php / footer_*.php # Layouts público e admin
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── img/
├── uploads/                # Fotos enviadas (excluídas do git)
└── database/
    └── schema.sql          # Schema completo (tabelas + índices + seeds)
```

### Banco de dados (tabelas)

`admin_users`, `miniatures`, `miniature_photos`, `miniature_tags`,
`miniature_comments`, `miniature_likes`, `miniature_ratings`, `categories`,
`tags`, `wishlist`, `user_follows`, `notifications`, `rate_limits`.

## Uso

### Visualização comum e suporte

Superadmins encontram em **Meu Perfil** os botões **Ver como usuário comum** e
**Acessar conta para suporte**. O menu Ferramentas também oferece esses acessos,
e a gestão de usuários tem um botão de suporte em cada conta comum.

O primeiro modo mostra os próprios dados sem privilégios administrativos. O
segundo permite consultar o painel de uma conta específica, incluindo contas
banidas, sem assumir privilégios de outro superadmin. Ambos são somente leitura:
alterações por POST são bloqueadas no servidor; mensagens e notificações não são
marcadas como lidas, visitas não são contabilizadas e o processamento automático
de e-mails não roda nessa sessão. Nenhuma notificação de acesso é enviada ao
usuário. As ações existentes continuam com suas regras normais fora desse modo.

Uma faixa identifica o modo e permite **Voltar à minha conta**. A visualização
vale para todas as abas da sessão e expira em 30 minutos. A identidade original
fica apenas na sessão do servidor; nenhum login persistente do usuário atendido
é criado. Privilégios e versão de autenticação do administrador são revalidados
em cada requisição. Revogação de privilégios ou troca de senha encerra o acesso.
O registro de administrador, conta, motivo, início e fim fica em
**Acesso de suporte → Registro interno de acessos**, sem conteúdo de mensagens.
Execute **Aplicar índices & migrations** para criar `support_access_log`.

### Avisos por e-mail e proteção de cadastro

Execute **Aplicar índices & migrations** após publicar. As estruturas novas estão
no único `database/schema.sql`, e o botão atualiza instalações existentes.

Não é necessário terminal nem cron. Em **Manutenção → Avisos por e-mail**, o
superadmin liga/desliga o processamento automático e usa
**Processar próximo lote** para enviar manualmente. As ações usam POST e CSRF.
Uma trava MySQL impede dois processamentos simultâneos.

O processamento automático ocorre após requisições PHP do site, um destinatário
de resumo e um de comunicado por
acesso. Em PHP-FPM, a resposta é encerrada antes do envio quando disponível.
Não existe um relógio independente: sem acessos, avisos e novas tentativas ficam
pendentes até o próximo acesso ou processamento manual na Manutenção. Em outros
ambientes PHP, o envio pode aumentar a duração da requisição.

Avisos de novos cadastros são destinados aos superadmins. Curtidas, comentários,
respostas, menções, estrelas e seguidores são destinados ao dono/interessado.
As preferências ficam em **Configurações → Preferências de e-mail**. Confirmação
e recuperação de senha não são desativadas por essas preferências. Os avisos
só são enviados a destinatários com e-mail confirmado e conta não banida.

Atividades são reunidas em um único resumo semanal por usuário, incluindo apenas
as categorias habilitadas nas preferências. O primeiro período começa na primeira
atividade e dura 7 dias; após um envio, o próximo só pode ocorrer 7 dias depois.
Sem atividade válida, nenhum e-mail é enviado. Conversas já lidas são excluídas.
Os antigos tempos por categoria não são mais usados. Pendências existentes
entram no primeiro período semanal, sem disparo em massa imediato na atualização.
Falhas têm até 5 tentativas, espaçadas por 15 minutos.

### Comunicados para a comunidade

Superadmins acessam **Ferramentas → Comunicados** para escrever e-mails com o
editor Quill (negrito, itálico, sublinhado, títulos, listas, alinhamento, links e
imagens). O editor é carregado via CDN, na versão fixa 2.0.3. Imagens JPG/PNG/WebP
de até 5 MB e 24 megapixels são validadas pelo MIME, redimensionadas e regravadas
em JPG/PNG em `uploads/campaigns/`, com no máximo 1600 pixels por dimensão para
compatibilidade em e-mails. HTML recebido é sanitizado no servidor; scripts,
atributos de eventos, URLs executáveis e imagens externas/base64 são removidos.

Há rascunhos, prévia desktop/mobile, envio de teste apenas ao próprio admin,
confirmação explícita antes do disparo e acompanhamento por destinatário.
Ao confirmar, cria-se uma lista única de destinatários ativos, com e-mail
confirmado, recebimento global habilitado e comunicados habilitados. Banimentos,
mudanças de e-mail e preferências são conferidos novamente em cada envio.
Teste e prévia não enfileiram a campanha. Rascunhos podem ser editados; campanhas
iniciadas ficam congeladas. Cancelar impede os próximos envios, mas não recolhe
e-mails já enviados ou em trânsito. Um erro após aceitação pelo SendGrid e antes
do registro no banco pode resultar em repetição; não há garantia de exactly-once.

O processamento pode continuar pela própria tela (um destinatário por vez), pela
Manutenção ou nos acessos normais ao site, sem terminal. Pausar o processamento
na tela não cancela a campanha nem desliga o automático. Para impedir próximos
envios, use **Interromper campanha**. Desativar **Comunicados e novidades** nas
preferências impede esses e-mails, sem alterar os resumos semanais habilitados.

Cadastros têm campo-armadilha invisível, tempo mínimo de preenchimento e rate
limit. Contas novas precisam confirmar o e-mail antes de enviar mensagens,
curtir, comentar ou seguir manualmente. Contas anteriores não são bloqueadas
retroativamente por essa regra. Os domínios `formtests.info` e
`immenseignite.info`, presentes nos cadastros indesejados observados, são
bloqueados por padrão; `BLOCKED_SIGNUP_DOMAINS` permite ajustar a lista. Não há
bloqueio genérico de Gmail, Mail.ru, nacionalidades ou nomes de usuário.

Para proteção mais forte, configure no arquivo local `TURNSTILE_SITE_KEY` e
`TURNSTILE_SECRET_KEY`, com widget autorizado para o domínio do `APP_URL`.
O desafio é validado no servidor (incluindo hostname e ação `signup`). Sem
chaves, continuam os controles locais, que não eliminam bots sofisticados.

O cadastro solicita o país de residência (lista ISO 3166-1, validada no servidor)
e também registra separadamente o país aproximado pelo IP. O painel de usuários
exibe os dois, sinaliza diferenças sem banir automaticamente e mostra o estado
de confirmação do e-mail. Usa o mesmo `geo_lookup` das estatísticas, com cabeçalho
Cloudflare confiável ou o fallback `GEO_LOOKUP_URL` já configurado. VPNs/proxies
podem alterar o resultado. Não infere país pelo e-mail, nem preenche cadastros
antigos sem evidência. Apenas os dois códigos de país são armazenados no perfil.

### E-mails de conta (SendGrid)

Adicione ao `includes/config.local.php`, preservando as configurações existentes:

```php
define('APP_URL', 'https://garage64.online');
define('SENDGRID_API_KEY', 'SUA_CHAVE_SENDGRID');
define('MAIL_FROM_EMAIL', 'notificacoes@garage64.online');
define('MAIL_FROM_NAME', 'Garage64');
```

Não duplique constantes que já existam no arquivo. A chave também pode vir da
variável de ambiente `SENDGRID_API_KEY`. O arquivo local já está no `.gitignore`.
Veja `includes/config.local.example.php` como referência, sem credenciais reais.
O remetente/domínio precisa estar autorizado no SendGrid. O servidor precisa de
PHP cURL com certificados TLS válidos e acesso HTTPS à API do SendGrid.

Após publicar, execute **Aplicar índices & migrations**. Não há SQL adicional.
Novos cadastros recebem um link de confirmação com validade de 24 horas. Contas
existentes mantêm o acesso e recebem um aviso no painel para confirmar o e-mail.
Alterar o endereço no perfil remove a confirmação e envia outro link.
O acesso permanece disponível enquanto a confirmação estiver pendente.

**Esqueci minha senha** envia um link válido por 30 minutos. Os links são de uso
único, armazenados apenas como hash e consumidos por POST, evitando confirmação
por scanners de e-mail. Redefinir a senha invalida sessões, logins persistentes e
outros links pendentes. A resposta de recuperação não informa se a conta existe.
Os templates HTML e texto estão em `includes/email_templates.php`, sem Dynamic
Templates. HTTP 202 significa aceitação pelo SendGrid, não entrega garantida;
confira a atividade no painel do provedor em caso de problemas.

### Atualização de interações, mensagens e login persistente

Depois de publicar os arquivos, entre como superadmin em `/admin/manutencao` e
execute **Aplicar índices & migrations**. O botão cria as tabelas de mensagens
privadas e tokens de login e habilita avisos de novos cadastros e mensagens,
sem precisar executar SQL manualmente. O `database/schema.sql` contém a
estrutura completa para instalações novas.

Faça login novamente após a migração para ativar a persistência por 30 dias.
Os tokens são armazenados como hash e revogados ao sair ou trocar a senha.
As notificações aparecem no menu público e no painel; os contadores são
atualizados a cada 30 segundos enquanto o site está visível. O chat consulta
novas mensagens a cada 5 segundos. Esta versão não envia e-mails nem push.
As confirmações de visualização usam `direct_messages.read_at`, adicionado pelo
mesmo botão de migrations. Apenas mensagens recebidas visíveis na conversa,
com a aba ativa e em foco, são confirmadas; abrir a página ou consultar novas
mensagens não marca a conversa inteira como lida. O horário da primeira
visualização é preservado, e mensagens antigas sem esse registro não recebem
um horário retroativo.
Curtidas, avaliações e comentários usam atualizações locais sem criar entradas
no histórico do navegador; sem JavaScript, os formulários tradicionais continuam
disponíveis.

- **Galeria pública:** `https://garage64.online/`
- **Coleção de um colecionador:** `https://garage64.online/u/{slug}`
- **Página da miniatura:** `https://garage64.online/mini/{id}`
- **Admin:** `https://garage64.online/admin/`
