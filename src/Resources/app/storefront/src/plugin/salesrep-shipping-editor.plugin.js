const PluginBaseClass = window.PluginBaseClass;

export default class SalesrepShippingEditorPlugin extends PluginBaseClass {
  init() {
    this.input   = this.el.querySelector('.js-sa-shipping-input');
    this.apply   = this.el.querySelector('.js-sa-shipping-apply');
    this.errorEl = this.el.querySelector('.js-sa-shipping-error');

    this.shippingId = this.el.dataset.shippingId;
    this.orig       = parseFloat(this.el.dataset.orig || '0');
    this.limit      = parseFloat(this.el.dataset.limit || '0');

    if (!this.input || !this.apply || !this.shippingId || !this.orig) return;

    if (this.limit <= 0) {
      this.input.disabled = true;
      this.apply.disabled = true;
      this.showError('Discount is disabled for this shipping method.');
      return;
    }

    this.apply.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      this.clearError();

      if (this.limit <= 0) {
        return this.showError('Discount is disabled for this shipping method.');
      }

      const val = parseFloat((this.input.value || '').replace(',', '.'));
      if (isNaN(val) || val <= 0) {
        return this.showError('Enter a valid price.');
      }

      if (val > this.orig + 1e-6) {
        return this.showError(`Price cannot exceed ${this.orig.toFixed(2)}.`);
      }

      const minAllowed = this.orig * (1 - this.limit / 100);
      if (this.limit > 0 && val < minAllowed - 1e-6) {
        return this.showError(`Too low. Minimum allowed is ${minAllowed.toFixed(2)}.`);
      }

      await this.submit(val);
    });
  }

  async submit(price) {
    try {
      const resp = await fetch('/salesrep/shipping/price', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams({
          shippingId: this.shippingId,
          price: price.toString()
        })
      });

      if (!resp.ok) {
        let msg = 'Failed to apply shipping price.';
        try {
          const j = await resp.json();
          if (j?.error) msg = j.error;
        } catch(e) {
          console.warn(e);
        }
        return this.showError(msg);
      }

      window.location.reload();
    } catch {
      this.showError('Network error applying shipping price.');
    }
  }

  showError(msg) {
    if (this.errorEl) {
      this.errorEl.textContent = msg;
      this.errorEl.classList.remove('d-none');
    }
    if (this.input) this.input.classList.add('is-invalid');
  }

  clearError() {
    if (this.errorEl) {
      this.errorEl.textContent = '';
      this.errorEl.classList.add('d-none');
    }
    if (this.input) this.input.classList.remove('is-invalid');
  }
}
