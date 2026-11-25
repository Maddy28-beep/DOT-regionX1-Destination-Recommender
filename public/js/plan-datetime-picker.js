/* Floating UI only. Keep the original wall-clock datetime values and validation events. */
(() => {
    'use strict';
    const inputs = [...document.querySelectorAll('[data-trip-window] input[type="datetime-local"]')];
    if (!inputs.length || !window.HTMLDialogElement || !HTMLDialogElement.prototype.showModal) return;
    const pad = n => String(n).padStart(2, '0');
    const parse = value => /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value || '');
    const dateOf = value => { const m = parse(value); return m ? new Date(+m[1], +m[2]-1, +m[3], 12) : null; };
    const dayValue = date => `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}`;
    const format = value => {
        const m = parse(value); if (!m) return 'Select date & time';
        return `${dateOf(value).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'})} · ${+m[4]%12 || 12}:${m[5]} ${+m[4]<12?'AM':'PM'}`;
    };
    const fire = input => ['input','change'].forEach(type => input.dispatchEvent(new Event(type, {bubbles:true})));
    const dialog = document.createElement('dialog');
    dialog.className = 'dtp'; dialog.id = 'trip-datetime-picker'; dialog.setAttribute('aria-labelledby','dtp-title');
    dialog.innerHTML = `<header class="dtp__header"><h2 id="dtp-title"></h2><button type="button" class="dtp__icon" data-close aria-label="Close date and time picker">×</button></header>
        <div class="dtp__body"><section aria-label="Calendar"><div class="dtp__month"><button type="button" class="dtp__icon" data-prev aria-label="Previous month">‹</button><strong data-month aria-live="polite"></strong><button type="button" class="dtp__icon" data-next aria-label="Next month">›</button></div><div class="dtp__week" aria-hidden="true">${['Su','Mo','Tu','We','Th','Fr','Sa'].map(d=>`<span>${d}</span>`).join('')}</div><div class="dtp__days" role="group" aria-label="Choose a date"></div></section>
        <section class="dtp__time" aria-label="Time"><h3>Time</h3><p class="dtp__zone">Philippine time (PHT)</p><div class="dtp__clock"><label>Hour<input data-hour type="number" min="1" max="12" step="1" inputmode="numeric" aria-label="Hour"></label><b aria-hidden="true">:</b><label>Minute<input data-minute type="number" min="0" max="59" step="1" inputmode="numeric" aria-label="Minute"></label></div><div class="dtp__period" role="group" aria-label="AM or PM"><button type="button" data-period="AM" aria-pressed="true">AM</button><button type="button" data-period="PM" aria-pressed="false">PM</button></div><p class="dtp__summary" aria-live="polite"></p></section></div>
        <p class="dtp__error" role="alert"></p><footer class="dtp__footer"><button type="button" class="dtp__cancel" data-close>Cancel</button><button type="button" class="dtp__apply">Apply date &amp; time</button></footer>`;
    document.body.append(dialog);
    const el = selector => dialog.querySelector(selector);
    const hour = el('[data-hour]'), minute = el('[data-minute]'), error = el('.dtp__error');
    let active, trigger, selected, month, period = 'AM';
    const candidate = () => `${dayValue(selected)}T${pad((+hour.value%12)+(period==='PM'?12:0))}:${pad(+minute.value)}`;
    const summarize = () => {
        el('.dtp__summary').textContent = hour.value && minute.value!=='' ? format(candidate()) : 'Choose your time';
        error.textContent = '';
    };
    const render = () => {
        el('[data-month]').textContent = month.toLocaleDateString('en-GB',{month:'long',year:'numeric'});
        const grid = el('.dtp__days'); grid.replaceChildren();
        for(let i=0;i<month.getDay();i++) grid.append(document.createElement('span'));
        const count = new Date(month.getFullYear(),month.getMonth()+1,0).getDate();
        const min = active.min.slice(0,10), max = active.max.slice(0,10);
        const today = document.querySelector('[data-trip-window]').dataset.now.slice(0,10);
        for(let n=1;n<=count;n++) {
            const date = new Date(month.getFullYear(),month.getMonth(),n,12), value = dayValue(date);
            const button = document.createElement('button'); button.type='button'; button.className='dtp__day'; button.textContent=n;
            button.dataset.date=value; button.setAttribute('aria-label',date.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'}));
            button.setAttribute('aria-pressed',String(value===dayValue(selected)));
            button.disabled = Boolean((min && value<min) || (max && value>max));
            if(value===today) { button.classList.add('is-today'); button.setAttribute('aria-current','date'); }
            grid.append(button);
        }
    };
    const position = () => {
        if(!dialog.open || matchMedia('(max-width:600px)').matches) return;
        const r = trigger.getBoundingClientRect(), w=dialog.offsetWidth, h=dialog.offsetHeight;
        dialog.style.left = `${Math.max(12,Math.min(r.left,innerWidth-w-12))}px`;
        const below = r.bottom+8;
        dialog.style.top = `${Math.max(12, below+h<=innerHeight-12 ? below : r.top-h-8>=12 ? r.top-h-8 : (innerHeight-h)/2)}px`;
    };
    const open = input => {
        if(dialog.open) return;
        active=input; trigger=document.getElementById(input.id+'-picker');
        const fallback=input.min || document.querySelector('[data-trip-window]').dataset.now;
        const value=input.value || fallback, parts=parse(value);
        selected=dateOf(value) || new Date(); month=new Date(selected.getFullYear(),selected.getMonth(),1,12);
        const h=parts?+parts[4]:9; hour.value=h%12||12; minute.value=parts?parts[5]:'00'; period=h<12?'AM':'PM';
        dialog.querySelectorAll('[data-period]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.period===period)));
        el('#dtp-title').textContent=input.id==='start_at'?'Choose start date & time':'Choose end date & time';
        render();summarize(); trigger.setAttribute('aria-expanded','true'); dialog.showModal();position();
        (el('.dtp__day[aria-pressed="true"]:not(:disabled)') || el('.dtp__day:not(:disabled)') || hour).focus({preventScroll:true});
    };
    inputs.forEach(input => {
        const button=document.createElement('button');button.type='button';button.className='dtp-trigger';button.id=input.id+'-picker';
        button.setAttribute('aria-haspopup','dialog');button.setAttribute('aria-controls',dialog.id);button.setAttribute('aria-expanded','false');
        button.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6M17 2v6M3 11h18"/></svg><span></span>';
        const label=document.querySelector(`label[for="${input.id}"]`);
        if(label) { if(!label.id) label.id=input.id+'-label'; button.setAttribute('aria-labelledby',label.id+' '+button.id+'-value'); label.htmlFor=button.id; }
        button.querySelector('span').id=button.id+'-value';
        input.after(button);input.classList.add('dtp-native');input.tabIndex=-1;input.setAttribute('aria-hidden','true');
        const sync=()=>{button.querySelector('span').textContent=format(input.value);button.classList.toggle('is-empty',!input.value);button.setAttribute('aria-invalid',String(!input.validity.valid && Boolean(input.value)));};
        button.addEventListener('click',()=>open(input));input.addEventListener('change',sync);input.addEventListener('input',sync);
        input.addEventListener('invalid',event=>{event.preventDefault();if(!dialog.open) {open(input);error.textContent=input.validationMessage;}});
        sync();
    });
    dialog.addEventListener('click',event=>{
        const button=event.target.closest('button');
        if(button?.matches('[data-close]')) dialog.close();
        if(button?.dataset.date) {selected=new Date(button.dataset.date+'T12:00:00');render();summarize();el(`[data-date="${button.dataset.date}"]`).focus({preventScroll:true});}
        if(button?.matches('[data-prev],[data-next]')) {month=new Date(month.getFullYear(),month.getMonth()+(button.hasAttribute('data-next')?1:-1),1,12);render();}
        if(button?.dataset.period) {period=button.dataset.period;dialog.querySelectorAll('[data-period]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));summarize();}
        if(button?.matches('.dtp__apply')) {
            if(!hour.checkValidity() || !minute.checkValidity() || !hour.value || minute.value==='') {error.textContent='Enter an hour from 1 to 12 and a minute from 00 to 59.';(!hour.validity.valid || !hour.value ? hour : minute).focus();return;}
            const previous=active.value;active.value=candidate();fire(active);
            if(!active.checkValidity()) {const message=active.validationMessage;active.value=previous;fire(active);error.textContent=message;position();return;}
            dialog.close();
        }
        if(event.target===dialog) {const r=dialog.getBoundingClientRect();if(event.clientX<r.left || event.clientX>r.right || event.clientY<r.top || event.clientY>r.bottom)dialog.close();}
    });
    dialog.addEventListener('close',()=>{trigger?.setAttribute('aria-expanded','false');trigger?.focus({preventScroll:true});});
    [hour,minute].forEach(input=>input.addEventListener('input',summarize));
    dialog.addEventListener('keydown',event=>{
        const button=event.target.closest('[data-date]');
        if(!button || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key))return;
        event.preventDefault();const date=new Date(button.dataset.date+'T12:00:00');
        const delta={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7,Home:-date.getDay(),End:6-date.getDay()}[event.key];date.setDate(date.getDate()+delta);
        const value=dayValue(date);if((active.min && value<active.min.slice(0,10)) || (active.max && value>active.max.slice(0,10)))return;
        selected=date;month=new Date(date.getFullYear(),date.getMonth(),1,12);render();summarize();el(`[data-date="${value}"]`)?.focus({preventScroll:true});
    });
    addEventListener('resize',position);addEventListener('scroll',position,true);
})();
