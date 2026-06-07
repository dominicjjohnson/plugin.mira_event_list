document.addEventListener('DOMContentLoaded', function () {

    function initRepeater(listId, addBtnId, rowClass) {
        var list   = document.getElementById(listId);
        var addBtn = document.getElementById(addBtnId);
        if (!list || !addBtn) return;

        var template = list.querySelector('[data-template]');
        if (!template) return;

        var index = list.querySelectorAll('.' + rowClass + ':not([data-template])').length;

        addBtn.addEventListener('click', function () {
            var row = template.cloneNode(true);
            row.removeAttribute('data-template');
            row.style.display = '';
            row.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace(/__IDX__/g, index);
            });
            list.insertBefore(row, template);
            index++;
        });

        list.addEventListener('click', function (e) {
            if (e.target.classList.contains('mira-remove-row')) {
                e.target.closest('.' + rowClass).remove();
            }
        });
    }

    initRepeater('mira-charities-list', 'add-event-charity', 'mira-charity-row');
    initRepeater('mira-people-list',    'add-event-person',  'mira-person-row');
});
