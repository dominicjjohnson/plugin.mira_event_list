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

        // Fire-and-forget: records what actually happened on a failed booking
        // attempt, including cases (like a WAF block) that never reach our
        // own PHP code at all, so we have real evidence instead of relying on
        // customers to report it.
        function logClientError(ajaxUrl, eventId, status, note) {
            try {
                var params = new URLSearchParams();
                params.append('action', 'mira_log_client_error');
                params.append('event_id', eventId || '');
                params.append('status', status || '');
                params.append('note', note || '');
                params.append('ua', navigator.userAgent);
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(ajaxUrl, params);
                } else {
                    fetch(ajaxUrl, { method: 'POST', body: params, keepalive: true });
                }
            } catch (e) { /* logging must never break the booking flow */ }
        }

        // The nonce in the page can be stale when the page came from cache,
        // so fetch a current one first. Any failure falls back to the page's
        // nonce, which is no worse than before.
        function freshNonce(form) {
            var params = new URLSearchParams();
            params.append('action', 'mira_refresh_nonce');
            return fetch(form.dataset.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.success && data.data.nonce) form.dataset.nonce = data.data.nonce;
                    return form.dataset.nonce;
                })
                .catch(function () { return form.dataset.nonce; });
        }

        function showBookingFailure(errorEl, fallback, message) {
            if (fallback) {
                errorEl.innerHTML = (message || 'Something went wrong.') +
                    ' You can also <a href="' + fallback + '" target="_blank" rel="noopener">pay via this backup link</a> — ' +
                    'email us your name afterward so we can send your ticket.';
            } else {
                errorEl.textContent = message || 'Something went wrong. Please try again.';
            }
        }

        function submitBooking(form, email) {
            var btn      = form.querySelector('.mira-book-btn');
            var errorEl  = form.querySelector('.mira-booking-error');
            var qty      = form.querySelector('[name="quantity"]').value;
            var donInput = form.querySelector('[name="donation"]');
            var donation = donInput ? (donInput.value || 0) : 0;
            var eventId  = form.dataset.eventId;
            var ajaxUrl  = form.dataset.ajaxUrl;
            var fallback = form.dataset.fallbackUrl;

            btn.disabled    = true;
            btn.textContent = 'Processing…';
            errorEl.innerHTML = '';

            freshNonce(form)
                .then(function (nonce) {
                    var fd = new FormData();
                    fd.append('action',     'mira_create_booking');
                    fd.append('nonce',      nonce);
                    fd.append('event_id',   eventId);
                    fd.append('quantity',   qty);
                    fd.append('donation',   donation);
                    fd.append('lead_email', email);
                    fd.append('coupon',     form.dataset.couponCode || '');
                    return fetch(ajaxUrl, { method: 'POST', body: fd });
                })
                .then(function (r) {
                    if (!r.ok) {
                        logClientError(ajaxUrl, eventId, r.status, 'http_not_ok');
                        if (r.status === 403) {
                            var expired = new Error('HTTP 403');
                            expired.expired = true;
                            throw expired;
                        }
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function (data) {
                    if (data.success) {
                        window.location.href = data.data.url;
                    } else {
                        logClientError(ajaxUrl, eventId, 200, 'app_error: ' + (data.data || ''));
                        showBookingFailure(errorEl, fallback, data.data);
                        btn.disabled    = false;
                        btn.textContent = btn.dataset.label;
                    }
                })
                .catch(function (err) {
                    logClientError(ajaxUrl, eventId, 0, 'fetch_exception: ' + err.message);
                    showBookingFailure(errorEl, fallback, err.expired
                        ? 'This page has expired. Please refresh the page and try again.'
                        : 'Connection error.');
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

        // ── Coupon codes ─────────────────────────────────────────────────
        // Only events with a coupon get the link. The code is checked on the
        // server (and checked again when the booking is made), so the
        // discounted price can't be set from the browser.
        function formatPrice(n) { return '£' + n.toFixed(2); }

        function setTicketPrice(form, price) {
            form.dataset.ticketPrice = price;
            var btn = form.querySelector('.mira-book-btn');
            if (btn) {
                btn.dataset.label = formatPrice(price) + ' per ticket — Book Now';
                if (!btn.disabled) btn.textContent = btn.dataset.label;
            }
            updateTotal(form);
        }

        document.querySelectorAll('.mira-booking-form[data-has-coupon]').forEach(function (form) {
            form.dataset.basePrice = form.dataset.ticketPrice;

            var wrap = document.createElement('div');
            wrap.className = 'mira-coupon';
            wrap.innerHTML =
                '<a href="#" class="mira-coupon-toggle">Have a coupon code?</a>' +
                '<div class="mira-coupon-fields" hidden>' +
                    '<input type="text" class="mira-coupon-input" placeholder="Coupon code" ' +
                        'autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false" maxlength="50" ' +
                        'aria-label="Coupon code">' +
                    '<button type="button" class="mira-coupon-apply">Apply</button>' +
                '</div>' +
                '<p class="mira-coupon-msg" role="status"></p>';
            form.appendChild(wrap);

            var toggle = wrap.querySelector('.mira-coupon-toggle');
            var fields = wrap.querySelector('.mira-coupon-fields');
            var input  = wrap.querySelector('.mira-coupon-input');
            var apply  = wrap.querySelector('.mira-coupon-apply');
            var msg    = wrap.querySelector('.mira-coupon-msg');

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                fields.hidden = false;
                toggle.hidden = true;
                input.focus();
            });

            function removeCoupon(e) {
                if (e) e.preventDefault();
                delete form.dataset.couponCode;
                setTicketPrice(form, parseFloat(form.dataset.basePrice) || 0);
                input.value = '';
                input.disabled = false;
                apply.hidden = false;
                msg.className = 'mira-coupon-msg';
                msg.textContent = '';
                input.focus();
            }

            function applyCoupon() {
                var code = input.value.trim();
                msg.className = 'mira-coupon-msg';
                if (!code) {
                    msg.classList.add('is-error');
                    msg.textContent = 'Please enter a coupon code.';
                    return;
                }
                apply.disabled = true;
                apply.textContent = 'Checking…';
                msg.textContent = '';

                freshNonce(form)
                    .then(function (nonce) {
                        var fd = new FormData();
                        fd.append('action',   'mira_check_coupon');
                        fd.append('nonce',    nonce);
                        fd.append('event_id', form.dataset.eventId);
                        fd.append('coupon',   code);
                        return fetch(form.dataset.ajaxUrl, { method: 'POST', body: fd });
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        apply.disabled = false;
                        apply.textContent = 'Apply';
                        if (data.success) {
                            var price = parseFloat(data.data.price) || 0;
                            form.dataset.couponCode = code;
                            setTicketPrice(form, price);
                            input.disabled = true;
                            apply.hidden = true;
                            msg.classList.add('is-success');
                            msg.innerHTML = '';
                            msg.appendChild(document.createTextNode(
                                'Coupon applied — ' + formatPrice(price) + ' per ticket. '));
                            var rm = document.createElement('a');
                            rm.href = '#';
                            rm.className = 'mira-coupon-remove';
                            rm.textContent = 'Remove';
                            rm.addEventListener('click', removeCoupon);
                            msg.appendChild(rm);
                        } else {
                            msg.classList.add('is-error');
                            msg.textContent = data.data || 'That coupon code isn\'t valid.';
                        }
                    })
                    .catch(function () {
                        apply.disabled = false;
                        apply.textContent = 'Apply';
                        msg.classList.add('is-error');
                        msg.textContent = 'Connection error. Please try again.';
                    });
            }

            apply.addEventListener('click', applyCoupon);
            // Enter in the coupon box applies the coupon instead of submitting the booking.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); }
            });
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
                            '<p>Check your inbox &mdash; tickets have been emailed to everyone listed. See you at the event!</p>' +
                            ((data.data && data.data.social) ? data.data.social : '');
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
