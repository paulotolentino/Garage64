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

### 1. Banco de dados

```bash
mysql -u root -p < database/schema.sql
```

### 2. Configuração

Copie e edite o arquivo de configuração:

```bash
cp includes/config.php includes/config.local.php
# Edite includes/config.local.php com suas credenciais
```

### 3. Criar o usuário admin

```bash
php setup.php
```

### 4. Permissões

```bash
chmod 755 uploads/
```

### 5. Servidor web

Configure seu Apache ou Nginx para apontar para o diretório raiz do projeto com suporte a `.htaccess` (AllowOverride All).

## Estrutura

```
├── index.php               # Galeria pública
├── miniature.php           # Detalhes da miniatura (público)
├── setup.php               # Script de configuração inicial (CLI)
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

- **Galeria pública:** `http://seusite.com/`
- **Página da miniatura:** `http://seusite.com/miniature.php?id=1`
- **Admin:** `http://seusite.com/admin/`
