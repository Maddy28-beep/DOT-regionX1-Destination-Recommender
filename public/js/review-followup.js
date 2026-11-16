document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-review-followup]').forEach(function (form) {
        var comment = form.querySelector('[data-review-comment]');
        var counter = form.querySelector('[data-review-counter]');
        if (comment && counter) {
            var count = function () { counter.textContent = comment.value.length + '/' + comment.maxLength; };
            comment.addEventListener('input', count);
            count();
        }
        form.querySelectorAll('[data-star-optional] input').forEach(function (radio) {
            var label = form.querySelector('label[for="' + radio.id + '"]');
            var wasChecked = false;
            if (!label) return;
            label.addEventListener('pointerdown', function () { wasChecked = radio.checked; });
            label.addEventListener('click', function (event) {
                if (wasChecked) { event.preventDefault(); radio.checked = false; wasChecked = false; }
            });
        });
    });
});
