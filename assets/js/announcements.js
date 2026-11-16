(() => {
    const feedback=document.getElementById('campaign-feedback');
    if(typeof Quill==='undefined') { feedback.textContent='Não foi possível carregar o editor. Atualize a página.';return; }
    const initial=JSON.parse(document.getElementById('campaign-initial').textContent);
    document.getElementById('campaign-history-toggle').addEventListener('click',event=>{const open=document.querySelector('.campaign-history').classList.toggle('is-open');event.currentTarget.setAttribute('aria-expanded',String(open));});
    const form=document.getElementById('campaign-form');
    const editor=new Quill('#campaign-editor',{theme:'snow',readOnly:!initial.editable,modules:{toolbar:'#campaign-toolbar'}});
    editor.clipboard.dangerouslyPasteHTML(initial.html);
    let busy=false,running=false,processing=false,editable=initial.editable,dirty=false,timer;
    editor.on('text-change',(_delta,_old,source)=>{if(source==='user')dirty=true;});
    document.getElementById('campaign-subject').addEventListener('input',()=>{dirty=true;});
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    function payload(action) {
        const data=new FormData(form);data.set('action','email_campaign_'+action);data.set('body_html',editor.getSemanticHTML());return data;
    }
    async function request(action,extra={}) {
        const data=payload(action);for(const [key,value] of Object.entries(extra))data.set(key,value);
        const response=await fetch('/admin/announcements',{method:'POST',body:data});
        if(response.redirected)throw new Error('Entre novamente como superadmin.');
        const result=await response.json();if(!response.ok || !result.ok)throw new Error(result.message || 'Não foi possível concluir.');return result;
    }
    async function guard(work) {
        if(busy)return;busy=true;form.setAttribute('aria-busy','true');feedback.textContent='Processando…';
        const controls=[...document.querySelectorAll('.campaign-actions button')];controls.forEach(el=>el.disabled=true);
        try{await work();}catch(error){feedback.textContent=error.message;}finally{busy=false;form.removeAttribute('aria-busy');controls.forEach(el=>el.disabled=false);}
    }
    async function save() {
        if(!form.reportValidity())throw new Error('Informe o assunto do comunicado.');
        const result=await request('save');document.getElementById('campaign-id').value=result.id;
        history.replaceState(null,'','/admin/announcements?id='+result.id);
        document.getElementById('campaign-eligible').textContent=result.eligible;feedback.textContent=result.message;dirty=false;
        let entry=document.querySelector('[data-campaign-history-id="'+result.id+'"]');
        if(!entry){entry=document.createElement('a');entry.href='/admin/announcements?id='+result.id;entry.dataset.campaignHistoryId=result.id;entry.className='campaign-history-item is-active';document.querySelector('.campaign-history-list > .campaign-muted')?.remove();document.querySelector('.campaign-history-list').append(entry);}
        const title=document.createElement('strong');title.textContent=document.getElementById('campaign-subject').value;const status=document.createElement('span');status.textContent='Rascunho';entry.replaceChildren(title,status);
        return result;
    }
    form.addEventListener('submit',event=>{event.preventDefault();guard(save);});
    document.querySelectorAll('[data-campaign-action]').forEach(button=>button.addEventListener('click',()=>guard(async()=>{
        const action=button.dataset.campaignAction;
        if(!form.reportValidity())return;
        const result=await request(action);
        if(action==='preview'){document.getElementById('campaign-preview-frame').srcdoc=result.html;bootstrap.Modal.getOrCreateInstance(document.getElementById('campaignPreview')).show();feedback.textContent='';}
        else feedback.textContent=result.message;
    })));
    const file=document.getElementById('campaign-image');
    editor.getModule('toolbar').addHandler('image',()=>{if(editable)file.click();});
    file.addEventListener('change',()=>guard(async()=>{
        if(!file.files[0])return;
        const data=payload('upload');data.set('image',file.files[0]);
        const response=await fetch('/admin/announcements',{method:'POST',body:data});
        if(response.redirected)throw new Error('Entre novamente como superadmin.');
        const result=await response.json();
        if(!response.ok || !result.ok)throw new Error(result.message || 'Falha ao enviar imagem.');
        const position=editor.getSelection(true)?.index ?? editor.getLength()-1;editor.insertEmbed(position,'image',result.url,'user');editor.setSelection(position+1);file.value='';feedback.textContent='Imagem adicionada.';
    }));
    document.querySelectorAll('[data-preview-width]').forEach(button=>button.addEventListener('click',()=>{document.getElementById('campaign-preview-frame').style.width=button.dataset.previewWidth;}));
    const review=document.getElementById('campaign-review');
    review?.addEventListener('click',()=>guard(async()=>{await save();document.getElementById('campaign-confirm-check').checked=false;document.getElementById('campaign-send').disabled=true;bootstrap.Modal.getOrCreateInstance(document.getElementById('campaignConfirm')).show();}));
    document.getElementById('campaign-confirm-check').addEventListener('change',event=>{document.getElementById('campaign-send').disabled=!event.target.checked;});
    function counts(data) {
        document.getElementById('campaign-delivery').hidden=false;
        document.querySelectorAll('[data-campaign-count]').forEach(el=>{el.textContent=data[el.dataset.campaignCount];});
        const progress=document.getElementById('campaign-progress');progress.max=Math.max(1,data.total);progress.value=data.sent+data.failed+data.skipped;
        if(data.pending===0){running=false;document.getElementById('campaign-status').textContent='Concluído';feedback.textContent='Envio concluído. Confira os totais acima.';}
    }
    async function processNext() {
        if(!running || processing || document.hidden)return;
        processing=true;
        try{
            const result=await request('process');counts(result.counts);
            if(running && result.result.sent+result.result.skipped+result.result.failed===0){running=false;feedback.textContent='Há pendências aguardando nova tentativa ou outro processamento. Você pode continuar mais tarde.';}
            if(running)timer=setTimeout(processNext,1200);
        }
        catch(error){running=false;feedback.textContent=error.message;}
        finally{processing=false;}
    }
    document.getElementById('campaign-send').addEventListener('click',()=>guard(async()=>{
        const button=document.getElementById('campaign-send');if(button.disabled)return;button.disabled=true;
        const result=await request('queue',{confirm:'1'});bootstrap.Modal.getOrCreateInstance(document.getElementById('campaignConfirm')).hide();
        editable=false;editor.enable(false);document.getElementById('campaign-subject').readOnly=true;document.getElementById('campaign-status').textContent='Em envio';
        document.querySelectorAll('.campaign-actions > .campaign-actions-secondary:last-child button').forEach(el=>el.hidden=true);
        counts(result.counts);feedback.textContent='Envio iniciado. Você pode acompanhar nesta tela.';running=result.counts.pending>0;processNext();
    }));
    document.getElementById('campaign-continue')?.addEventListener('click',()=>{if(!running){running=true;processNext();}});
    document.getElementById('campaign-pause')?.addEventListener('click',()=>{running=false;clearTimeout(timer);feedback.textContent='Processamento nesta tela pausado. O envio automático do site pode continuar.';});
    document.getElementById('campaign-cancel')?.addEventListener('click',()=>{if(confirm('Interromper os próximos envios? E-mails já enviados não podem ser recolhidos.'))guard(async()=>{running=false;clearTimeout(timer);const result=await request('cancel');feedback.textContent=result.message;document.getElementById('campaign-status').textContent='Interrompido';});});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden && running)processNext();});
})();
