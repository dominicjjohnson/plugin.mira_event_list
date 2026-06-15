(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ── Booking forms on event cards ─────────────────────────────────
        document.querySelectorAll('.mira-booking-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var btn      = form.querySelector('.mira-book-btn');
                var errorEl  = form.querySelector('.mira-booking-error');
                var qty      = form.querySelector('[name="quantity"]').value;
                var donInput = form.querySelector('[name="donation"]');
                var donation = donInput ? (donInput.value || 0) : 0;

                btn.disabled    = true;
                btn.textContent = 'Processing…';
                errorEl.textContent = '';

                var fd = new FormData();
                fd.append('action',   'mira_create_booking');
                fd.append('nonce',    form.dataset.nonce);
                fd.append('event_id', form.dataset.eventId);
                fd.append('quantity', qty);
                fd.append('donation', donation);

                fetch(form.dataset.ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            window.location.href = data.data.url;
                        } else {
                            errorEl.textContent = data.data || 'Something went wrong. Please try again.';
                            btn.disabled    = false;
                            btn.textContent = btn.dataset.label;
                        }
                    })
                    .catch(function () {
                        errorEl.textContent = 'Connection error. Please try again.';
                        btn.disabled    = false;
                        btn.textContent = btn.dataset.label;
                    });
            });
        });

        // ── Live total update ────────────────────────────────────────────
        function updateTotal(form) {
            var el = form.querySelector('.mira-total-amount');
            if (!el) return;
            var price    = parseFloat(form.dataset.ticketPrice) || 0;
            var qty      = parseInt(form.querySelector('[name="quantity"]').value, 10) || 1;
            var donInput = form.querySelector('[name="donation"]');
            var donation = donInput ? (parseFloat(donInput.value) || 0) : 0;
            el.textContent = '£' + ((price * qty) + donation).toFixed(2);
        }

        document.querySelectorAll('.mira-booking-form').forEach(function (form) {
            form.querySelector('[name="quantity"]').addEventListener('change', function () { updateTotal(form); });
            var don = form.querySelector('[name="donation"]');
            if (don) don.addEventListener('input', function () { updateTotal(form); });
        });

        // ── Attendee form on success / booking-complete page ─────────────
        var af = document.getElementById('mira-attendees-form');
        if (!af) return;

        af.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn   = af.querySelector('.mira-submit-btn');
            var msgEl = document.getElementById('mira-attendees-message');

            btn.disabled    = true;
            btn.textContent = 'Sending tickets…';
            msgEl.textContent = '';

            var fd = new FormData(af);
            fd.append('action', 'mira_save_attendees');
            fd.append('nonce',  af.dataset.nonce);

            fetch(af.dataset.ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        var wrap = af.closest('.mira-success-page') || af.parentElement;
                        var ok   = document.createElement('div');
                        ok.className = 'mira-tickets-sent';
                        ok.innerHTML =
                            '<div class="mira-confirmed-icon">&#10003;</div>' +
                            '<h3>Tickets sent!</h3>' +
                            '<p>Check your inbox &mdash; tickets have been emailed to everyone listed. See you at the event!</p>';
                        af.replaceWith(ok);
                    } else {
                        msgEl.textContent = data.data || 'Something went wrong. Please try again.';
                        btn.disabled    = false;
                        btn.textContent = 'Send My Tickets';
                    }
                })
                .catch(function () {
                    msgEl.textContent = 'Connection error. Please try again.';
                    btn.disabled    = false;
                    btn.textContent = 'Send My Tickets';
                });
        });

    });
}());
