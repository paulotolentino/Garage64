/* In-place interactions never change the URL or browser history. */
(() => {
    function showError(form, message) {
        let error = form.querySelector('[data-interaction-error]');
        if (!error) {
            error = document.createElement('p');
            error.dataset.interactionError = '';
            error.setAttribute('role', 'alert');
            error.className = 'text-danger mt-2';
            form.append(error);
        }
        error.textContent = message;
    }
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!document.body.classList.contains('md-page') ||
            !form.matches('.md-like-form, .md-rating-form, .cm-form, .cm-pin-form, .cm-delete-form')) return;
        const cancelled = event.defaultPrevented;
        event.preventDefault();
        if (cancelled || form.dataset.busy) return;
        const body = new FormData(form);
        if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
        form.dataset.busy = '1';
        const buttons = [...form.querySelectorAll('button')];
        buttons.forEach(button => { button.disabled = true; });
        try {
            const response = await fetch(form.action, {
                method: 'POST', body, headers: { 'X-Requested-With': 'Garage64' },
            });
            if (response.redirected) throw new Error('Sua sessão expirou. Entre novamente para continuar.');
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Não foi possível concluir. Atualize a página e tente novamente.');
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Não foi possível salvar.');
            const fresh = await fetch(location.pathname, { cache: 'no-store' });
            if (!fresh.ok) throw new Error('A ação foi salva, mas não foi possível atualizar a tela.');
            const doc = new DOMParser().parseFromString(await fresh.text(), 'text/html');
            const scrollX = window.scrollX;
            const scrollY = window.scrollY;
            const selectors = form.matches('.md-like-form') ? ['.md-like-wrapper'] :
                form.matches('.md-rating-form') ? ['.md-rating', '.md-hero-rating'] : ['#comments'];
            for (const selector of selectors) {
                const previous = document.querySelector(selector);
                const next = doc.querySelector(selector);
                if (previous && next) previous.replaceWith(next);
            }
            window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });
        } catch (error) {
            showError(form, error.message || 'Erro de rede. Tente novamente.');
        } finally {
            delete form.dataset.busy;
            buttons.forEach(button => { button.disabled = false; });
        }
    });
    let polling = false;
    async function updateActivity() {
        if (polling || document.hidden || !document.querySelector('[data-notification-count], [data-message-count]')) return;
        polling = true;
        try {
            const response = await fetch('/admin/activity.php', { cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            document.querySelectorAll('[data-notification-count]').forEach(el => { el.textContent = data.notifications || ''; });
            document.querySelectorAll('[data-message-count]').forEach(el => { el.textContent = data.messages || ''; });
        } catch (_) { /* Retry on the next interval. */ }
        finally { polling = false; }
    }
    updateActivity();
    setInterval(updateActivity, 30000);
    document.addEventListener('visibilitychange', updateActivity);
})();
