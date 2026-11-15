<?php require_once __DIR__ . '/header_admin.php'; ?>
<div class="inbox-heading">
    <div><p class="inbox-eyebrow">Comunidade</p><h1>Mensagens</h1></div>
    <button type="button" class="md-btn md-btn-primary" data-bs-toggle="modal" data-bs-target="#newConversation"><i class="fa fa-pen-to-square"></i>Nova conversa</button>
</div>
<?php if ($error): ?><p class="text-danger" role="alert"><?= e($error) ?></p><?php endif; ?>
<div class="inbox <?= $peer ? 'inbox-has-peer' : '' ?>">
    <aside class="inbox-sidebar" aria-label="Conversas">
        <div class="inbox-sidebar-heading"><h2>Conversas</h2><span><?= count($conversations) ?></span></div>
        <label class="inbox-filter"><i class="fa fa-magnifying-glass"></i><input id="inbox-filter" type="search" placeholder="Buscar conversa" aria-label="Buscar conversa"></label>
        <div class="inbox-conversations">
        <?php foreach ($conversations as $conversation): $name = $conversation['display_name'] ?: $conversation['username']; ?>
            <a class="inbox-contact <?= (int) $conversation['id'] === $peer_id ? 'is-active' : '' ?>" href="/admin/messages?user=<?= (int) $conversation['id'] ?>" <?= (int) $conversation['id'] === $peer_id ? 'aria-current="page"' : '' ?> data-contact-name="<?= e(mb_strtolower($name . ' ' . $conversation['slug'])) ?>">
                <span class="inbox-avatar"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                <span class="inbox-contact-copy"><strong><?= e($name) ?></strong><span><?= e($conversation['preview'] ?? ('@' . $conversation['slug'])) ?></span></span>
                <span class="inbox-contact-meta"><time><?= !empty($conversation['last_at']) ? e(date('d/m', strtotime($conversation['last_at']))) : '' ?></time><?php if ($conversation['unread']): ?><b><?= (int) $conversation['unread'] ?></b><?php endif; ?></span>
            </a>
        <?php endforeach; ?>
        <p class="inbox-list-empty" id="inbox-filter-empty" <?= $conversations ? 'hidden' : '' ?>><?= $conversations ? 'Nenhuma conversa encontrada.' : 'Suas conversas aparecem aqui.' ?></p>
        </div>
    </aside>
    <section class="inbox-thread" aria-label="Conversa selecionada">
    <?php if ($peer && $ready): $peer_name = $peer['display_name'] ?: $peer['username']; ?>
        <header class="inbox-thread-heading">
            <a class="inbox-back" href="/admin/messages" aria-label="Voltar às conversas" title="Voltar às conversas"><i class="fa fa-arrow-left"></i></a>
            <span class="inbox-avatar"><?= e(mb_strtoupper(mb_substr($peer_name, 0, 1))) ?></span>
            <div class="inbox-peer"><h2><?= e($peer_name) ?></h2><span>@<?= e($peer['slug']) ?></span></div>
            <a class="inbox-profile" href="/u/<?= e($peer['slug']) ?>" title="Ver garagem" aria-label="Ver garagem de <?= e($peer_name) ?>"><i class="fa fa-warehouse"></i></a>
        </header>
        <div id="message-list" class="inbox-messages" role="log" aria-label="Mensagens da conversa" aria-live="polite">
            <?php if (count($messages) === 100): ?><a class="inbox-history" href="/admin/messages?user=<?= $peer_id ?>&amp;before=<?= (int) $messages[0]['id'] ?>">Mensagens anteriores</a><?php endif; ?>
            <?php if (!empty($before)): ?><a class="inbox-history" href="/admin/messages?user=<?= $peer_id ?>">Mensagens recentes</a><?php endif; ?>
            <?php if (!$messages): ?><div class="inbox-thread-empty" id="inbox-thread-empty"><i class="fa fa-comment-dots"></i><p>Comece uma conversa com <?= e($peer_name) ?>.</p></div><?php endif; ?>
            <?php $last_day = ''; foreach ($messages as $message): $day = date('d/m/Y', strtotime($message['created_at'])); ?>
                <?php if ($day !== $last_day): ?><div class="inbox-date"><?= e($day) ?></div><?php $last_day = $day; endif; ?>
                <div class="inbox-message <?= (int) $message['sender_id'] === $me ? 'is-mine' : '' ?>" data-message-id="<?= (int) $message['id'] ?>" data-incoming="<?= (int) $message['sender_id'] !== $me ? '1' : '0' ?>" data-seen="<?= !empty($message['read_at']) ? '1' : '0' ?>">
                    <div class="inbox-bubble"><p><?= e($message['body']) ?></p><time title="<?= e($message['created_at']) ?>">Enviada <?= e(date('H:i', strtotime($message['created_at']))) ?></time><?php if ((int) $message['sender_id'] === $me): ?><small class="inbox-receipt"><?= !empty($message['read_at']) ? 'Vista ' . e(date('d/m H:i', strtotime($message['read_at']))) : '' ?></small><?php endif; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" id="message-form" class="inbox-composer">
            <?= csrf_field() ?>
            <div class="inbox-compose-row"><textarea name="body" maxlength="2000" rows="1" required aria-label="Mensagem" placeholder="Escreva uma mensagem…"></textarea><button type="submit" title="Enviar mensagem" aria-label="Enviar mensagem"><i class="fa fa-paper-plane"></i></button></div>
            <div class="inbox-compose-meta"><p id="message-error" role="alert"></p><span id="message-length">0 / 2000</span></div>
        </form>
    <?php else: ?>
        <div class="inbox-empty"><img src="/assets/img/logo.svg" alt="" width="56" height="56"><h2>Boas conversas começam aqui</h2><p>Conecte-se com outros colecionadores.</p><button type="button" class="md-btn" data-bs-toggle="modal" data-bs-target="#newConversation"><i class="fa fa-pen-to-square"></i>Nova conversa</button></div>
    <?php endif; ?>
    </section>
</div>
<div class="modal fade inbox-modal" id="newConversation" tabindex="-1" aria-labelledby="newConversationTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="newConversationTitle">Nova conversa</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><label for="conversation-search" class="form-label">Nome ou @usuário</label><input type="search" id="conversation-search" class="form-control" autocomplete="off" maxlength="100" placeholder="Quem você procura?"><p id="conversation-search-status" class="inbox-search-status" role="status">Digite pelo menos 2 caracteres.</p><div id="conversation-search-results"></div></div>
    </div></div>
</div>
<script src="/assets/js/messages.js?v=20261115c" defer></script>
<?php require_once __DIR__ . '/footer_admin.php'; ?>
