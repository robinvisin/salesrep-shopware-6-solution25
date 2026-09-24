const Plugin = window.PluginBaseClass;


export default class ZipcodeAutofillPlugin extends Plugin {

    init() {
        this.onZipChange = this.onZipChange.bind(this);
        console.log('ZipcodeAutofillPlugin initialized');
        this.el.addEventListener('blur', this.onZipChange);
        this.el.addEventListener('change', this.onZipChange);
    }

    async onZipChange() {
        const zip = this.el.value.trim();
        if (!zip || zip.length !== 5) {
            return;
        }

        const prefix = this.el.dataset.addressPrefix || (this.el.name ? this.el.name.split('[')[0] : '');
        const idPrefix = this.el.dataset.addressIdPrefix || '';

        const cityEl = document.getElementById(`${idPrefix}${prefix}AddressCity`);
        const stateSelect = document.getElementById(`${idPrefix}${prefix}AddressCountryState`);

        try {
            const res = await fetch(`https://api.zippopotam.us/us/${zip}`);
            if (!res.ok) {
                return;
            }

            const data = await res.json();
            if (!data.places || !data.places.length) {
                return;
            }

            const place = data.places[0];

            if (cityEl) {
                cityEl.value = place['place name'] || '';
                cityEl.dispatchEvent(new Event('input', { bubbles: true }));
                cityEl.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (stateSelect) {
                const abbr = place['state abbreviation'];
                const full = place['state'];
                let matched = false;

                Array.from(stateSelect.options).forEach((opt) => {
                    const text = opt.textContent.trim();
                    if (text === abbr || text === full) {
                        opt.selected = true;
                        matched = true;
                    }
                });

                if (matched) {
                    stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        } catch (e) {
        }
    }
}
