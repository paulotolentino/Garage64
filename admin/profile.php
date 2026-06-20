<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$error   = '';
$success = false;

// Load current user data
$user = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
$user->execute([current_user_id()]);
$user = $user->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $display_name = trim($_POST['display_name'] ?? '');
    $bio          = trim($_POST['bio'] ?? '');
    $new_slug     = strtolower(trim($_POST['slug'] ?? $user['slug'] ?? ''));
    $new_password = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    // ─── Slug validation ──────────────────────────────────────────────────────
    $RESERVED_SLUGS = ['admin','register','login','logout','install','setup','sitemap','robots','mini','u','collections','assets','uploads','database','includes','api'];
    if (!preg_match('/^[a-z0-9_-]{2,30}$/', $new_slug) || in_array($new_slug, $RESERVED_SLUGS)) {
        $error = 'Slug inválido (2–30 chars: letras minúsculas, números, _ e -) ou reservado.';
    } else {
        $chk = db()->prepare('SELECT id FROM admin_users WHERE slug = ? AND id != ?');
        $chk->execute([$new_slug, current_user_id()]);
        if ($chk->fetch()) $error = 'Este slug já está em uso.';
    }

    // ─── Avatar upload ───────────────────────────────────────────────────────
    $avatar = $user['avatar']; // keep existing by default

    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);

        if (!in_array($mime, $allowed, true)) {
            $error = 'Formato de imagem inválido. Use JPG, PNG, WebP ou GIF.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'A imagem deve ter no máximo 5 MB.';
        } else {
            $avatars_dir = UPLOADS_DIR . 'avatars/';
            if (!is_dir($avatars_dir)) {
                mkdir($avatars_dir, 0755, true);
            }

            $filename = current_user_id() . '.webp';
            $dest     = $avatars_dir . $filename;

            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                'image/png'  => imagecreatefrompng($file['tmp_name']),
                'image/gif'  => imagecreatefromgif($file['tmp_name']),
                default      => imagecreatefromstring(file_get_contents($file['tmp_name'])),
            };

            if ($image) {
                // Crop to square and resize to 200x200
                $src_w = imagesx($image);
                $src_h = imagesy($image);
                $side  = min($src_w, $src_h);
                $src_x = (int)(($src_w - $side) / 2);
                $src_y = (int)(($src_h - $side) / 2);

                $thumb = imagecreatetruecolor(200, 200);
                imagecopyresampled($thumb, $image, 0, 0, $src_x, $src_y, 200, 200, $side, $side);
                imagewebp($thumb, $dest, 85);
                imagedestroy($image);
                imagedestroy($thumb);
                $avatar = $filename;
            } else {
                $error = 'Não foi possível processar a imagem.';
            }
        }
    }

    // ─── Password change ─────────────────────────────────────────────────────
    $hash = null;
    if ($new_password !== '') {
        if (strlen($new_password) < 8) {
            $error = $error ?: 'A nova senha deve ter no mínimo 8 caracteres.';
        } elseif ($new_password !== $confirm_pass) {
            $error = $error ?: 'As senhas não coincidem.';
        } else {
            $hash = password_hash($new_password, PASSWORD_BCRYPT);
        }
    }

    if (!$error) {
        if ($hash) {
            db()->prepare(
                'UPDATE admin_users SET display_name = ?, bio = ?, avatar = ?, slug = ?, password_hash = ? WHERE id = ?'
            )->execute([$display_name, $bio ?: null, $avatar ?: null, $new_slug, $hash, current_user_id()]);
        } else {
            db()->prepare(
                'UPDATE admin_users SET display_name = ?, bio = ?, avatar = ?, slug = ? WHERE id = ?'
            )->execute([$display_name, $bio ?: null, $avatar ?: null, $new_slug, current_user_id()]);
        }
        // Refresh session
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['user_slug']      = $new_slug;
        $success = true;
        // Reload user data
        $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([current_user_id()]);
        $user = $stmt->fetch();
    }
}

$page_title = 'Meu Perfil';
require_once __DIR__ . '/../includes/header_admin.php';
?>

<div class="d-flex align-items-center mb-4">
    <h1 class="h4 mb-0"><i class="fa fa-user-circle me-2 text-warning"></i>Meu Perfil</h1>
</div>

<?php if ($success): ?>
    <div class="alert alert-success py-2"><i class="fa fa-check me-2"></i>Perfil atualizado com sucesso.</div>
<?php elseif ($error): ?>
    <div class="alert alert-danger py-2"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-md-4 text-center">
        <?php if (!empty($user['avatar'])): ?>
            <img src="<?= e(avatar_url($user['avatar'])) ?>"
                 alt="Avatar"
                 class="rounded-circle mb-3"
                 style="width:120px;height:120px;object-fit:cover;">
        <?php else: ?>
            <div class="rounded-circle bg-warning d-flex align-items-center justify-content-center mx-auto mb-3 fw-bold text-dark"
                 style="width:120px;height:120px;font-size:3rem;">
                <?= mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        <div class="text-light fw-semibold"><?= e($user['username']) ?></div>
        <div class="text-secondary small">
            <a href="/u/<?= e($user['slug'] ?? $user['username']) ?>" target="_blank" class="text-secondary">
                /u/<?= e($user['slug'] ?? $user['username']) ?> <i class="fa fa-external-link fa-xs"></i>
            </a>
        </div>
    </div>

    <div class="col-12 col-md-8">
        <form method="post" enctype="multipart/form-data" class="card bg-dark border-secondary">
            <div class="card-body">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label text-secondary">URL pública (slug)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-dark border-secondary text-secondary">/u/</span>
                        <input type="text" name="slug"
                               class="form-control bg-dark text-light border-secondary"
                               value="<?= e($user['slug'] ?? $user['username']) ?>"
                               pattern="[a-z0-9_\-]{2,30}" required>
                    </div>
                    <small class="text-secondary">2–30 caracteres: letras minúsculas, números, _ e -.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary">Nome de exibição</label>
                    <input type="text" name="display_name" class="form-control bg-dark text-light border-secondary"
                           value="<?= e($user['display_name'] ?? '') ?>"
                           placeholder="Como aparece publicamente">
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary">Bio</label>
                    <textarea name="bio" rows="3" class="form-control bg-dark text-light border-secondary"
                              placeholder="Fale um pouco sobre sua coleção..."><?= e($user['bio'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label text-secondary">Foto da coleção</label>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif"
                           class="form-control bg-dark text-light border-secondary">
                    <small class="text-secondary">JPG, PNG ou WebP. Máx 5 MB. Será cortada em quadrado 200×200 px.</small>
                </div>

                <hr class="border-secondary">
                <p class="text-secondary small mb-2">Deixe em branco para manter a senha atual.</p>

                <div class="mb-3">
                    <label class="form-label text-secondary">Nova senha</label>
                    <input type="password" name="new_password" class="form-control bg-dark text-light border-secondary"
                           minlength="8" autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label class="form-label text-secondary">Confirmar nova senha</label>
                    <input type="password" name="confirm_password" class="form-control bg-dark text-light border-secondary"
                           minlength="8" autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-warning">
                    <i class="fa fa-save me-1"></i>Salvar
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin.php'; ?>
