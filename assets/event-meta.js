document.addEventListener('DOMContentLoaded', function () {

    // ── Generic repeater ─────────────────────────────────────────────────
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
            // If the new row has a doc-upload button, wire it up
            var uploadBtn = row.querySelector('.mira-doc-upload');
            if (uploadBtn) wireDocUpload(uploadBtn);
        });

        list.addEventListener('click', function (e) {
            if (e.target.classList.contains('mira-remove-row')) {
                e.target.closest('.' + rowClass).remove();
            }
        });
    }

    initRepeater('mira-faqs-list',      'add-event-faq',     'mira-faq-row');
    initRepeater('mira-documents-list', 'add-event-document','mira-doc-row');

    // ── WP media picker for documents ────────────────────────────────────
    function wireDocUpload(btn) {
        btn.addEventListener('click', function () {
            var row      = btn.closest('.mira-doc-row');
            var idInput  = row.querySelector('.mira-doc-id');
            var urlInput = row.querySelector('.mira-doc-url');
            var label    = row.querySelector('.mira-doc-filename');

            var frame = wp.media({
                title:    'Select Document',
                button:   { text: 'Use this file' },
                multiple: false,
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                idInput.value  = attachment.id;
                urlInput.value = attachment.url;
                if (label) label.textContent = attachment.filename || attachment.url.split('/').pop();
            });

            frame.open();
        });
    }

    // Wire existing document upload buttons
    document.querySelectorAll('.mira-doc-upload').forEach(wireDocUpload);
});
