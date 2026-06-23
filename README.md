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

- **Galeria pública:** `https://garage64.online/`
- **Coleção de um colecionador:** `https://garage64.online/u/{slug}`
- **Página da miniatura:** `https://garage64.online/mini/{id}`
- **Admin:** `https://garage64.online/admin/`
