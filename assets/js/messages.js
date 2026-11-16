(() => {
    const filter = document.getElementById('inbox-filter');
    filter?.addEventListener('input', () => {
        let visible = 0;
        document.querySelectorAll('[data-contact-name]').forEach(contact => {
            contact.hidden = !contact.dataset.contactName.includes(filter.value.trim().toLocaleLowerCase());
            if (!contact.hidden) visible++;
        });
        document.getElementById('inbox-filter-empty').hidden = visible > 0;
    });
    const search = document.getElementById('conversation-search');
    if (search) {
        const results = document.getElementById('conversation-search-results');
        const status = document.getElementById('conversation-search-status');
        let timer, controller;
        document.getElementById('newConversation').addEventListener('shown.bs.modal', () => search.focus());
        search.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            results.replaceChildren();
            const query = search.value.trim();
            if (query.length < 2) { status.textContent = 'Digite pelo menos 2 caracteres.'; return; }
            status.textContent = 'Buscando…';
            controller = new AbortController();
            const signal = controller.signal;
            timer = setTimeout(async () => {
                try {
                    const url = new URL('/admin/messages', location.origin);
                    url.searchParams.set('search_users', '1');
                    url.searchParams.set('q', query);
                    const response = await fetch(url, { signal, cache: 'no-store' });
                    if (response.redirected) throw new Error('Entre novamente para buscar usuários.');
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.error || 'Não foi possível buscar usuários.');
                    if (signal.aborted) return;
                    status.textContent = data.users.length ? '' : 'Nenhum colecionador encontrado.';
                    for (const user of data.users) {
                        const link = document.createElement('a');
                        link.href = '/admin/messages?user=' + encodeURIComponent(user.id);
                        link.className = 'inbox-search-result';
                        const name = document.createElement('strong');
                        name.className = 'd-block';
                        name.textContent = user.display_name || user.username;
                        const handle = document.createElement('small');
                        handle.className = 'text-secondary';
                        handle.textContent = '@' + user.slug;
                        link.append(name, handle);
                        results.append(link);
                    }
                } catch (error) {
                    if (!signal.aborted) status.textContent = error.message;
                }
            }, 300);
        });
    }
    const form = document.getElementById('message-form');
    const list = document.getElementById('message-list');
    if (!form || !list) return;
    const input = form.elements.body;
    function resizeComposer() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
        const count = document.getElementById('message-length');
        if (count) count.textContent = input.value.length + ' / 2000';
    }
    input.addEventListener('input', resizeComposer);
    let loading = false;
    let lastId = list.lastElementChild?.dataset.messageId || '0';
    list.scrollTop = list.scrollHeight;
    let seeing = false;
    async function markVisible() {
        if (document.documentElement.dataset.readonlyMode==='1') return;
        if (seeing || document.hidden || !document.hasFocus() || document.querySelector('.modal.show, .admin-sidebar-overlay.visible')) return;
        const root = list.getBoundingClientRect();
        const rows = [...list.querySelectorAll('[data-incoming="1"]:not([data-seen="1"])')].filter(row => {
            const rect = row.getBoundingClientRect();
            const height = Math.min(root.bottom, innerHeight, rect.bottom) - Math.max(root.top, 0, rect.top);
            const width = Math.min(root.right, innerWidth, rect.right) - Math.max(root.left, 0, rect.left);
            return height >= Math.min(rect.height, root.height, innerHeight) * 0.75 && width > 0;
        }).slice(0, 100);
        if (!rows.length) return;
        seeing = true;
        try {
            const body = new FormData();
            body.set('action', 'mark_seen');
            body.set('csrf_token', form.elements.csrf_token.value);
            rows.forEach(row => body.append('message_ids[]', row.dataset.messageId));
            const response = await fetch(location.href, { method: 'POST', body, headers: { 'X-Requested-With': 'Garage64' } });
            if (!response.ok || response.redirected) return;
            const data = await response.json();
            if (data.ok) rows.forEach(row => { row.dataset.seen = '1'; });
        } finally { seeing = false; }
    }
    const seenTimer = setInterval(() => markVisible().catch(() => {}), 1000);
    window.addEventListener('pagehide', () => clearInterval(seenTimer), { once: true });
    async function refresh(forceScroll = false) {
        if (loading || document.hidden) return;
        loading = true;
        try {
            const url = new URL(location.href);
            url.searchParams.delete('before');
            url.searchParams.set('poll', '1');
            const response = await fetch(url, { cache: 'no-store' });
            if (!response.ok || response.redirected) throw new Error('Não foi possível atualizar a conversa.');
            const data = await response.json();
            const atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
            for (const message of data.messages) {
                const existing = list.querySelector('[data-message-id="' + String(BigInt(message.id)) + '"]');
                if (existing) {
                    const receipt = existing.querySelector('.inbox-receipt');
                    if (receipt && message.read_at) receipt.textContent = 'Vista ' + message.read_at.slice(8, 10) + '/' + message.read_at.slice(5, 7) + ' ' + message.read_at.slice(11, 16);
                }
                if (BigInt(message.id) <= BigInt(lastId)) continue;
                const row = document.createElement('div');
                row.className = 'inbox-message' + (Number(message.sender_id) === data.me ? ' is-mine' : '');
                row.dataset.messageId = message.id;
                row.dataset.incoming = Number(message.sender_id) !== data.me ? '1' : '0';
                row.dataset.seen = message.read_at ? '1' : '0';
                const bubble = document.createElement('div');
                bubble.className = 'inbox-bubble';
                const body = document.createElement('p');
                body.textContent = message.body;
                const date = document.createElement('time');
                date.title = message.created_at;
                date.textContent = 'Enviada ' + message.created_at.slice(11, 16);
                bubble.append(body, date);
                if (Number(message.sender_id) === data.me) {
                    const receipt = document.createElement('small');
                    receipt.className = 'inbox-receipt';
                    receipt.textContent = message.read_at ? 'Vista ' + message.read_at.slice(8, 10) + '/' + message.read_at.slice(5, 7) + ' ' + message.read_at.slice(11, 16) : '';
                    bubble.append(receipt);
                }
                row.append(bubble);
                document.getElementById('inbox-thread-empty')?.remove();
                list.append(row);
                lastId = String(message.id);
            }
            if (atBottom || forceScroll) list.scrollTop = list.scrollHeight;
        } catch (error) {
            document.getElementById('message-error').textContent = error.message;
        } finally { loading = false; }
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button');
        if (button.disabled) return;
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        const sendIcon = button.querySelector('i');
        if (sendIcon) sendIcon.className = 'fa fa-spinner fa-spin';
        const error = document.getElementById('message-error');
        error.textContent = '';
        try {
            const response = await fetch(location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'Garage64' } });
            if (response.redirected) throw new Error('Entre novamente para enviar mensagens.');
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível enviar.');
            form.elements.body.value = '';
            resizeComposer();
            await refresh(true);
        } catch (err) { error.textContent = err.message; }
        finally {
            button.disabled = false;
            form.removeAttribute('aria-busy');
            if (sendIcon) sendIcon.className = 'fa fa-paper-plane';
        }
    });
    setInterval(() => refresh(), 5000);
    document.addEventListener('visibilitychange', () => refresh());
})();
