# Garage64

Aplicação web para catalogação e gerenciamento de coleções de miniaturas diecast.

## Stack

- **Backend:** PHP 8.3+
- **Banco de dados:** MySQL 8+
- **Frontend:** HTML5, Bootstrap 5, Vanilla JS, Font Awesome

## Funcionalidades

- Galeria pública da coleção (com busca e filtros)
- Página de detalhes de cada miniatura
- Dashboard privado com estatísticas
- CRUD completo de miniaturas (com upload de fotos, tags, avaliação emocional, dados financeiros)
- Sistema de Wishlist com conversão para coleção
- Gerenciamento de categorias e tags
- Modo público / privado (informações sensíveis visíveis apenas ao dono)

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
# Edite includes/config.local.php com suas credenciais
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

Configure seu Apache ou Nginx para apontar para o diretório raiz do projeto com suporte a `.htaccess` (AllowOverride All).

## Estrutura

```
├── index.php               # Galeria pública
├── miniature.php           # Detalhes da miniatura (público)
├── install.php             # Instalador via navegador (bloqueado após uso)
├── setup.php               # Script de configuração inicial (CLI)
├── installed.lock          # Criado pelo instalador; bloqueia /install.php
├── admin/
│   ├── index.php           # Dashboard
│   ├── login.php
│   ├── logout.php
│   ├── miniatures.php      # CRUD de miniaturas
│   ├── wishlist.php
│   ├── categories.php
│   └── tags.php
├── includes/
│   ├── config.php          # Configurações
│   ├── db.php              # Conexão PDO
│   ├── auth.php            # Autenticação
│   └── functions.php       # Funções auxiliares
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── img/
├── uploads/                # Fotos enviadas (excluídas do git)
└── database/
    └── schema.sql
```

## Uso

- **Galeria pública:** `http://garage64.online/`
- **Página da miniatura:** `http://garage64.online/miniature.php?id=1`
- **Admin:** `http://garage64.online/admin/`
