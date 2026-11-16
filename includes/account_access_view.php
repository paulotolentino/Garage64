<?php
$complete = $done || ($verify && $already_verified && $token === '');
$inbox_state = $email_sent || ($request_mode && $notice !== '');
$link_invalid = $link_invalid ?? false;
$heading = $complete ? ($verify ? 'E-mail confirmado' : 'Senha atualizada')
    : ($inbox_state ? 'Confira sua caixa de entrada' : ($verify && $token !== '' ? ($link_invalid ? 'Este link não está disponível' : 'Falta só um passo') : $title));
$icon = $complete ? 'fa-circle-check' : ($inbox_state ? 'fa-envelope-circle-check' : ($verify ? 'fa-envelope' : 'fa-lock'));
$back_url = $account_logged ? '/admin/' : '/admin/login';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($title) ?> | Garage64</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon-cart.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=20261115-account2">
</head>
<body class="account-access-page">
    <header class="account-access-header">
        <a class="account-access-brand" href="/"><img src="/assets/img/logo.svg" width="30" height="28" alt=""><span>Garage64</span></a>
        <a class="account-access-header-link" href="<?= e($back_url) ?>"><?= $account_logged ? 'Meu painel' : 'Entrar' ?><i class="fa fa-arrow-right" aria-hidden="true"></i></a>
    </header>
    <main class="account-access-main">
        <div class="account-access-symbol <?= $complete ? 'is-complete' : '' ?>" aria-hidden="true"><i class="fa <?= e($icon) ?>"></i></div>
        <p class="account-access-eyebrow">Segurança da conta</p>
        <h1><?= e($heading) ?></h1>
        <?php if ($complete): ?>
            <p class="account-access-description"><?= $verify ? 'Tudo certo. Seu endereço de e-mail está confirmado e sua garagem está pronta para a próxima novidade.' : 'Sua nova senha já está valendo. Entre novamente para acessar sua garagem.' ?></p>
        <?php elseif ($verify): ?>
            <p class="account-access-description"><?= $email_sent ? 'Enviamos um link de confirmação para seu e-mail. Abra a mensagem do Garage64 para concluir.' : ($token !== '' ? ($link_invalid ? 'Solicite um novo link de confirmação para continuar.' : 'Clique abaixo para confirmar seu e-mail.') : 'Confirme seu endereço para manter sua conta e o acesso à sua garagem em dia.') ?></p>
            <?php if ($account_address !== ''): ?><div class="account-access-address"><i class="fa fa-at" aria-hidden="true"></i><strong><?= e($account_address) ?></strong></div><?php endif; ?>
        <?php else: ?>
            <p class="account-access-description"><?= $inbox_state ? 'Se houver uma conta com o endereço informado, você receberá um link para escolher uma nova senha.' : ($request_mode ? 'Informe o e-mail da sua conta. Vamos enviar um link para você escolher uma nova senha.' : 'Escolha uma nova senha para voltar à sua garagem com segurança.') ?></p>
        <?php endif; ?>
        <?php if ($error): ?><p class="account-access-feedback is-error" role="alert"><i class="fa fa-circle-exclamation" aria-hidden="true"></i><span><?= e($error) ?></span></p><?php endif; ?>
        <?php if ($notice && !$complete): ?><p class="account-access-feedback" role="status"><i class="fa <?= $inbox_state ? 'fa-check' : 'fa-circle-info' ?>" aria-hidden="true"></i><span><?= e($notice) ?></span></p><?php endif; ?>
        <div class="account-access-actions">
        <?php if ($complete): ?>
            <a class="account-access-primary" href="<?= e($back_url) ?>"><?= $account_logged ? 'Ir para minha garagem' : 'Entrar na minha conta' ?><i class="fa fa-arrow-right" aria-hidden="true"></i></a>
        <?php elseif (!$inbox_state && !($verify && $link_invalid) && ($request_mode || $token !== '')): ?>
            <form method="post" action="<?= $request_mode ? '/forgot-password' : ($verify ? '/verify-email' : '/reset-password') ?>" class="account-access-form">
                <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
                <?php if ($request_mode): ?>
                    <label for="email">E-mail da conta</label><input id="email" name="email" type="email" required autocomplete="email" placeholder="voce@exemplo.com">
                <?php elseif (!$verify): ?>
                    <label for="password">Nova senha</label><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"><label for="password-confirm">Confirme a nova senha</label><input id="password-confirm" name="password_confirm" type="password" minlength="8" required autocomplete="new-password">
                <?php endif; ?>
                <button class="account-access-primary" type="submit"><?= $request_mode ? 'Enviar link de recuperação' : ($verify ? 'Confirmar meu e-mail' : 'Salvar nova senha') ?><i class="fa fa-arrow-right" aria-hidden="true"></i></button>
            </form>
        <?php endif; ?>
        <?php if ($verify && !$complete && $account_logged && ($link_invalid || ($token === '' && !$email_sent))): ?>
            <form method="post" action="/verify-email" class="account-access-resend">
                <?= csrf_field() ?><input type="hidden" name="action" value="resend">
                <button class="account-access-primary" type="submit"><i class="fa <?= $link_invalid ? 'fa-arrow-rotate-right' : 'fa-paper-plane' ?>" aria-hidden="true"></i><?= $link_invalid ? 'Reenviar confirmação' : 'Enviar e-mail de confirmação' ?></button>
            </form>
        <?php elseif ($verify && !$complete && !$account_logged && (!$token || $link_invalid)): ?>
            <a class="account-access-primary" href="/admin/login">Entrar para confirmar<i class="fa fa-arrow-right" aria-hidden="true"></i></a>
        <?php elseif (!$verify && !$request_mode && !$complete && !$token): ?>
            <a class="account-access-primary" href="/forgot-password">Solicitar novo link<i class="fa fa-arrow-right" aria-hidden="true"></i></a>
        <?php endif; ?>
        </div>
        <?php if ($inbox_state && !$complete): ?>
        <aside class="account-access-help"><i class="fa fa-inbox" aria-hidden="true"></i><div><h2>Não encontrou o e-mail?</h2><p>Confira o spam e a aba de promoções. O link vale por <?= $verify ? '24 horas' : '30 minutos' ?>.</p><?php if ($verify && $account_logged): ?><a href="/admin/profile">Corrigir meu endereço de e-mail</a><?php endif; ?></div></aside>
        <?php endif; ?>
        <?php if (!$complete): ?><a class="account-access-back" href="<?= e($back_url) ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i><?= $account_logged ? 'Voltar ao painel' : 'Voltar para entrar' ?></a><?php endif; ?>
    </main>
    <footer class="account-access-footer"><span>Garage64</span><span>Sua coleção. Sua garagem.</span></footer>
</body>
</html>
