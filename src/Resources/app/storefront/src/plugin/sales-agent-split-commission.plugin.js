import Plugin from 'src/plugin-system/plugin.class';

export default class SalesAgentSplitCommission extends Plugin {
    init() {
        console.log('loaded js')
        this.form = document.getElementById('confirmOrderForm');
        if (!this.form) return;

        this.noteCard = document.getElementById('sales-agent-cart-note');
        if (!this.noteCard) {
            return;
        }

        this.SAVE_URL = '/sales-agent/split';
        this.VALIDATE_URL = '/sales-agent/validate-email';

        this.nativeSubmit = HTMLFormElement.prototype.submit;

        this._syncing = false;
        this._allowPass = false;

        this._emailTimer = null;
        this._abortCtrl = null;
        this._lastValidatedEmail = '';
        this._lastEmailValid = null;

        this._ensureStyles();
        this._mountUI();
        this._bind();
    }

    _ensureStyles() {
        if (document.getElementById('sa-split-style')) return;

        const style = document.createElement('style');
        style.id = 'sa-split-style';
        style.textContent = `
            #sa-split-card { width: 100%; }
            #sa-split-card .sa-focusable { border: 1px solid #dde5ee !important; box-shadow: none; }
            #sa-split-card .sa-input-active { border: 1px solid #ab8355 !important; }
            #sa-split-card .sa-error-text { color: #dc3545; font-size: 12px; margin-top: 4px; display: none; }
            #sa-split-card .sa-has-error { border: 1px solid #dc3545 !important; }
            #sa-split-card .sa-hint { font-size: 12px; opacity: .75; margin-top: 6px; }
            #sa-split-card .sa-checking { color: #6c757d; }
            #sa-split-card .sa-ok { color: #198754; }
        `;
        document.head.appendChild(style);
    }

    _mountUI() {
        if (document.getElementById('sa-split-card')) return;

        const card = document.createElement('div');
        card.className = 'card checkout-card';
        card.id = 'sa-split-card';
        card.style.marginTop = '12px';
        card.style.marginBottom = '12px';

        card.innerHTML = `
            <div class="card-title">Split commission</div>

            <p style="margin: 0 0 12px 0; opacity: .85;">
                Optional: split your commission with another sales agent.
            </p>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="saSplitEmail" class="form-label">Sales agent email</label>
                <input type="email" id="saSplitEmail" class="form-control sa-focusable"
                       placeholder="agent@example.com" autocomplete="email">
                <div id="saSplitEmailErr" class="sa-error-text"></div>
                <div id="saSplitEmailHint" class="sa-hint"></div>
            </div>

            <div class="form-group" style="margin-bottom: 12px;">
                <label for="saSplitPercent" class="form-label">Percent (%)</label>
                <input type="text" id="saSplitPercent" class="form-control sa-focusable"
                       inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="10">
                <div id="saSplitPercentErr" class="sa-error-text"></div>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" id="saSplitClear" class="btn btn-secondary btn-sm">
                    Clear split
                </button>
            </div>
        `;

        this.noteCard.insertAdjacentElement('afterend', card);

        this.card = card;
        this.emailEl = card.querySelector('#saSplitEmail');
        this.percentEl = card.querySelector('#saSplitPercent');
        this.clearBtn = card.querySelector('#saSplitClear');

        this.emailErr = card.querySelector('#saSplitEmailErr');
        this.percentErr = card.querySelector('#saSplitPercentErr');
        this.emailHint = card.querySelector('#saSplitEmailHint');

        this.submitBtn =
            document.getElementById('confirmFormSubmit') ||
            this.form.querySelector('button[type="submit"]');

        [this.emailEl, this.percentEl].forEach((el) => {
            el.addEventListener('focus', () => el.classList.add('sa-input-active'));
            el.addEventListener('blur', () => el.classList.remove('sa-input-active'));
        });

        this.percentEl.addEventListener('keydown', (ev) => {
            const allowed = ['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'];
            if (allowed.includes(ev.key)) return;
            if (ev.ctrlKey || ev.metaKey) return;
            if (!/^\d$/.test(ev.key)) ev.preventDefault();
        });

        this.percentEl.addEventListener('input', () => {
            this._clearError(this.percentEl, this.percentErr);
            this.percentEl.value = this._clamp0to100(this.percentEl.value);
        });

        this.emailEl.addEventListener('input', () => {
            this._clearError(this.emailEl, this.emailErr);
            this._setHint('', '');
            this._lastEmailValid = null;
            this._scheduleEmailValidation();
        });

        this.emailEl.addEventListener('blur', () => {
            this._validateEmailNow(this.emailEl.value);
        });

        this.clearBtn.addEventListener('click', async () => {
            this.emailEl.value = '';
            this.percentEl.value = '';

            this._clearError(this.emailEl, this.emailErr);
            this._clearError(this.percentEl, this.percentErr);
            this._setHint('', '');

            try { await this._saveSplit('', 0); } catch (e) {}
        });
    }

    _bind() {
        if (!this.card) return;

        this.form.addEventListener('submit', this._handleBeforeSubmit.bind(this), true);
        if (this.submitBtn) {
            this.submitBtn.addEventListener('click', this._handleBeforeSubmit.bind(this), true);
        }
    }

    async _handleBeforeSubmit(ev) {
        if (!this.card) return;
        if (this._allowPass) return;

        if (this._syncing) {
            ev.preventDefault();
            ev.stopPropagation();
            ev.stopImmediatePropagation();
            return;
        }

        this._clearError(this.emailEl, this.emailErr);
        this._clearError(this.percentEl, this.percentErr);

        const email = (this.emailEl.value || '').trim();
        const percentStr = this._clamp0to100(this.percentEl.value);
        const percent = percentStr ? parseInt(percentStr, 10) : 0;

        const hasEmail = !!email;
        const hasPercent = percent > 0;

        if (!hasEmail && !hasPercent) return;

        if (hasEmail && !hasPercent) {
            ev.preventDefault(); ev.stopPropagation(); ev.stopImmediatePropagation();
            this._showError(this.percentEl, this.percentErr, 'Percent is required if you set an email');
            return;
        }
        if (!hasEmail && hasPercent) {
            ev.preventDefault(); ev.stopPropagation(); ev.stopImmediatePropagation();
            this._showError(this.emailEl, this.emailErr, 'Email is required if you set a percent');
            return;
        }

        if (!this._isEmailFormatOk(email)) {
            ev.preventDefault(); ev.stopPropagation(); ev.stopImmediatePropagation();
            this._showError(this.emailEl, this.emailErr, 'Enter a valid email format');
            return;
        }

        ev.preventDefault();
        ev.stopPropagation();
        ev.stopImmediatePropagation();

        this._syncing = true;
        this._setLoading(true);

        const okEmail =
            (email === this._lastValidatedEmail && this._lastEmailValid !== null)
                ? this._lastEmailValid
                : await this._validateEmailNow(email);

        if (okEmail !== true) {
            this._syncing = false;
            this._setLoading(false);
            return;
        }

        const okSave = await this._saveSplit(email, percent);
        if (!okSave) {
            this._syncing = false;
            this._setLoading(false);
            this._showError(this.emailEl, this.emailErr, 'Could not save split (server error)');
            return;
        }

        this._allowPass = true;
        this.nativeSubmit.call(this.form);
    }

    _setLoading(on) {
        if (!this.submitBtn) return;
        this.submitBtn.disabled = !!on;
    }

    _showError(input, box, msg) {
        if (input) input.classList.add('sa-has-error');
        if (box) {
            box.textContent = msg;
            box.style.display = 'block';
        }
    }

    _clearError(input, box) {
        if (input) input.classList.remove('sa-has-error');
        if (box) {
            box.textContent = '';
            box.style.display = 'none';
        }
    }

    _setHint(text, cls) {
        if (!this.emailHint) return;
        this.emailHint.className = 'sa-hint ' + (cls || '');
        this.emailHint.textContent = text || '';
    }

    _digitsOnly(raw) {
        return String(raw ?? '').replace(/[^\d]/g, '');
    }

    _clamp0to100(raw) {
        const d = this._digitsOnly(raw);
        if (!d) return '';
        let n = parseInt(d, 10);
        if (!Number.isFinite(n)) return '';
        n = Math.max(0, Math.min(100, n));
        return String(n);
    }

    _isEmailFormatOk(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    _scheduleEmailValidation() {
        const EMAIL_DEBOUNCE_MS = 350;
        if (this._emailTimer) clearTimeout(this._emailTimer);
        this._emailTimer = setTimeout(() => this._validateEmailNow(this.emailEl.value), EMAIL_DEBOUNCE_MS);
    }

    async _validateEmailNow(rawEmail) {
        const email = (rawEmail || '').trim();

        if (!email) {
            this._clearError(this.emailEl, this.emailErr);
            this._setHint('', '');
            this._lastValidatedEmail = '';
            this._lastEmailValid = null;
            return null;
        }

        if (!this._isEmailFormatOk(email)) {
            this._lastValidatedEmail = email;
            this._lastEmailValid = false;
            this._setHint('', '');
            this._showError(this.emailEl, this.emailErr, 'Enter a valid email format');
            return false;
        }

        if (email === this._lastValidatedEmail && this._lastEmailValid !== null) {
            return this._lastEmailValid;
        }

        if (this._abortCtrl) {
            try { this._abortCtrl.abort(); } catch (e) {}
        }
        this._abortCtrl = new AbortController();

        this._clearError(this.emailEl, this.emailErr);
        this._setHint('Checking email...', 'sa-checking');

        try {
            const res = await fetch(this.VALIDATE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ email }),
                signal: this._abortCtrl.signal
            });

            if (!res.ok) {
                this._lastValidatedEmail = email;
                this._lastEmailValid = false;
                this._setHint('', '');
                this._showError(this.emailEl, this.emailErr, 'Could not validate email (server error)');
                return false;
            }

            const data = await res.json();
            const ok = !!(data && data.valid === true);

            this._lastValidatedEmail = email;
            this._lastEmailValid = ok;

            if (ok) {
                this._clearError(this.emailEl, this.emailErr);
                this._setHint('Email OK', 'sa-ok');
                return true;
            }

            this._setHint('', '');
            this._showError(this.emailEl, this.emailErr, 'Sales agent email does not exist or is not a Sales Agent');
            return false;

        } catch (e) {
            if (e && e.name === 'AbortError') return null;

            this._lastValidatedEmail = email;
            this._lastEmailValid = false;
            this._setHint('', '');
            this._showError(this.emailEl, this.emailErr, 'Could not validate email (network error)');
            return false;
        }
    }

    async _saveSplit(email, percent) {
        try {
            const res = await fetch(this.SAVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: JSON.stringify({ email, percent })
            });

            if (!res.ok) return false;

            const data = await res.json();
            return !!(data && data.success);

        } catch (e) {
            return false;
        }
    }
}
