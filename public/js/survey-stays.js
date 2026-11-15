document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-survey-stays]').forEach(function (section) {
        var rows = section.querySelector('[data-stay-rows]');
        var add = section.querySelector('[data-add-stay]');
        var template = section.querySelector('[data-stay-template]');
        var next = 0;
        rows.querySelectorAll('[data-stay-row]').forEach(function (row) {
            var match = row.querySelector('select').name.match(/stays\[(\d+)\]/);
            if (match) next = Math.max(next, Number(match[1]) + 1);
        });
        function sync() {
            var all = rows.querySelectorAll('[data-stay-row]');
            all.forEach(function (row, index) {
                var manual = row.querySelector('[data-stay-choice]').value === 'other';
                row.querySelector('[data-stay-number]').textContent = index + 1;
                row.querySelector('[data-stay-manual]').hidden = !manual;
                row.querySelectorAll('[data-stay-manual] input').forEach(function (input) {
                    input.disabled = !manual;
                    input.required = manual;
                });
                row.querySelector('[data-remove-stay]').hidden = all.length === 1;
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
            if (target) target.querySelector('select').focus();
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
            rows.lastElementChild.querySelector('select').focus();
        });
        sync();
    });
});
