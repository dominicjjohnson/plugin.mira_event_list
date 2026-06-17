(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // ── Email capture modal ──────────────────────────────────────────
        var modal = document.createElement('div');
        modal.id = 'mira-email-modal';
        modal.innerHTML =
            '<div id="mira-email-modal-box">' +
                '<h3>Almost there!</h3>' +
                '<p>Enter your email so we can send your ticket.</p>' +
                '<input type="email" id="mira-email-input" placeholder="your@email.com" autocomplete="email">' +
                '<p id="mira-email-error" class="mira-booking-error"></p>' +
                '<div id="mira-email-modal-actions">' +
                    '<button type="button" id="mira-email-cancel" class="button">Cancel</button>' +
                    '<button type="button" id="mira-email-confirm" class="button button-primary">Continue to payment</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var pendingForm = null;

        document.getElementById('mira-email-cancel').addEventListener('click', function () {
            modal.style.display = 'none';
            pendingForm = null;
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.style.display = 'none';
                pendingForm = null;
            }
        });

        document.getElementById('mira-email-confirm').addEventListener('click', function () {
            var email   = document.getElementById('mira-email-input').value.trim();
            var errorEl = document.getElementById('mira-email-error');
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                errorEl.textContent = 'Please enter a valid email address.';
                return;
            }
            errorEl.textContent = '';
            modal.style.display = 'none';
            submitBooking(pendingForm, email);
        });

        document.getElementById('mira-email-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') document.getElementById('mira-email-confirm').click();
        });

        // ── Booking forms on event cards ─────────────────────────────────
        document.querySelectorAll('.mira-booking-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                pendingForm = form;
                document.getElementById('mira-email-input').value = '';
                document.getElementById('mira-email-error').textContent = '';
                modal.style.display = 'flex';
                document.getElementById('mira-email-input').focus();
            });
        });

        function submitBooking(form, email) {
            var btn      = form.querySelector('.mira-book-btn');
            var errorEl  = form.querySelector('.mira-booking-error');
            var qty      = form.querySelector('[name="quantity"]').value;
            var donInput = form.querySelector('[name="donation"]');
            var donation = donInput ? (donInput.value || 0) : 0;

            btn.disabled    = true;
            btn.textContent = 'Processing…';
            errorEl.textContent = '';

            var fd = new FormData();
            fd.append('action',     'mira_create_booking');
            fd.append('nonce',      form.dataset.nonce);
            fd.append('event_id',   form.dataset.eventId);
            fd.append('quantity',   qty);
            fd.append('donation',   donation);
            fd.append('lead_email', email);

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
        }

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
