document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-survey-stays]').forEach(function (section) {
        var rows = section.querySelector('[data-stay-rows]');
        var add = section.querySelector('[data-add-stay]');
        var template = section.querySelector('[data-stay-template]');
        var next = 0;
        function enhance(row) {
            if (row.querySelector('[data-stay-search]')) return;
            var select = row.querySelector('[data-stay-choice]');
            var options = Array.from(select.options).filter(function (option) { return option.value && !option.disabled; });
            var box = document.createElement('div');
            box.className = 'survey-stay-picker';
            var input = document.createElement('input');
            input.type = 'text';
            input.id = select.id + '-search';
            input.placeholder = 'Type an accommodation name, city or area…';
            input.autocomplete = 'off';
            input.spellcheck = false;
            input.setAttribute('data-stay-search', '');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-haspopup', 'listbox');
            input.setAttribute('aria-expanded', 'false');
            var list = document.createElement('div');
            list.id = select.id + '-matches';
            list.className = 'survey-stay-matches';
            list.setAttribute('role', 'listbox');
            list.setAttribute('aria-label', 'Matching accommodations and stay options');
            list.hidden = true;
            input.setAttribute('aria-controls', list.id);
            var status = document.createElement('p');
            status.className = 'field-hint survey-stay-search-status';
            status.id = select.id + '-search-status';
            status.setAttribute('role', 'status');
            input.setAttribute('aria-describedby', status.id);
            var label = row.querySelector('label[for="' + select.id + '"]');
            if (label) label.htmlFor = input.id;
            box.append(input, status, list);
            select.after(box);
            select.hidden = true;
            var active = -1;
            var visible = [];
            var selectedLabel = function () { return select.value ? select.selectedOptions[0].textContent.trim() : ''; };
            input.value = selectedLabel();
            function normalize(value) {
                return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().trim();
            }
            function close() {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
                active = -1;
            }
            row.closeStayPicker = close;
            function choose(option) {
                select.value = option.value;
                input.value = selectedLabel();
                input.setCustomValidity('');
                input.removeAttribute('aria-invalid');
                select.dispatchEvent(new Event('change', {bubbles: true}));
                close();
                status.textContent = 'Selected: ' + input.value;
                input.focus();
                close();
            }
            function render() {
                var query = input.value === selectedLabel() && select.value ? '' : normalize(input.value);
                var terms = query.split(/\s+/).filter(Boolean);
                var matches = options.filter(function (option) {
                    return option.value.startsWith('accommodation:') && terms.every(function (term) { return normalize(option.textContent).includes(term); });
                });
                var alternatives = options.filter(function (option) { return !option.value.startsWith('accommodation:'); });
                visible = query ? matches.concat(alternatives) : alternatives.concat(matches);
                active = -1;
                input.removeAttribute('aria-activedescendant');
                list.replaceChildren();
                visible.forEach(function (option, index) {
                    var item = document.createElement('div');
                    item.id = list.id + '-' + index;
                    item.className = 'survey-stay-match';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', String(select.value === option.value));
                    item.textContent = option.textContent.trim();
                    item.addEventListener('pointerdown', function (event) { event.preventDefault(); });
                    item.addEventListener('click', function () { choose(option); });
                    list.appendChild(item);
                });
                status.textContent = matches.length ? matches.length + ' accommodations found. Choose a result or Other.' : 'No accommodation matches. Choose Other / not in the list to enter it.';
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }
            input.addEventListener('focus', render);
            input.addEventListener('click', render);
            input.addEventListener('input', function () {
                select.selectedIndex = 0;
                select.dispatchEvent(new Event('change', {bubbles: true}));
                input.setCustomValidity(input.value.trim() ? 'Choose a matching accommodation, or choose Other / not in the list.' : '');
                input.removeAttribute('aria-invalid');
                render();
            });
            input.addEventListener('keydown', function (event) {
                if (event.isComposing) return;
                if (event.key === 'Escape') { event.preventDefault(); close(); return; }
                if (event.key === 'Tab') { close(); return; }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (!list.hidden && active >= 0) choose(visible[active]);
                    else render();
                    return;
                }
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                event.preventDefault();
                if (list.hidden) render();
                active = active < 0 ? (event.key === 'ArrowUp' ? visible.length - 1 : 0)
                    : (active + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length;
                Array.from(list.children).forEach(function (item, index) { item.classList.toggle('is-active', index === active); });
                input.setAttribute('aria-activedescendant', list.children[active].id);
                list.children[active].scrollIntoView({block: 'nearest'});
            });
            input.addEventListener('blur', close);
            input.addEventListener('invalid', function () { input.setAttribute('aria-invalid', 'true'); });
        }
        rows.querySelectorAll('[data-stay-row]').forEach(function (row) {
            var match = row.querySelector('select').name.match(/stays\[(\d+)\]/);
            if (match) next = Math.max(next, Number(match[1]) + 1);
        });
        function sync() {
            var all = rows.querySelectorAll('[data-stay-row]');
            all.forEach(function (row, index) {
                enhance(row);
                var manual = row.querySelector('[data-stay-choice]').value === 'other';
                row.querySelector('[data-stay-number]').textContent = index + 1;
                row.querySelector('[data-stay-manual]').hidden = !manual;
                row.querySelectorAll('[data-stay-manual] input').forEach(function (input) {
                    input.disabled = !manual;
                    input.required = manual;
                });
                row.querySelector('[data-remove-stay]').hidden = all.length === 1;
                row.querySelector('[data-remove-stay]').setAttribute('aria-label', 'Remove stay ' + (index + 1));
            });
            add.hidden = all.length >= 5;
        }
        rows.addEventListener('change', sync);
        rows.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-remove-stay]');
            if (!remove) return;
            var row = remove.closest('[data-stay-row]');
            var target = row.previousElementSibling || row.nextElementSibling;
            row.remove();
            sync();
            if (target) target.querySelector('[data-stay-search]').focus();
        });
        add.addEventListener('click', function () {
            if (rows.querySelectorAll('[data-stay-row]').length >= 5) return;
            var fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[name], [id], label[for]').forEach(function (element) {
                ['name', 'id', 'for'].forEach(function (attribute) {
                    if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(next)));
                });
            });
            next++;
            rows.appendChild(fragment);
            sync();
            rows.lastElementChild.querySelector('[data-stay-search]').focus();
        });
        section.addEventListener('pointerdown', function (event) {
            rows.querySelectorAll('[data-stay-row]').forEach(function (row) {
                if (!row.contains(event.target) && row.closeStayPicker) row.closeStayPicker();
            });
        });
        sync();
    });
});
