if (Shopware && Shopware.Utils && Shopware.Utils.format) {
    const format = Shopware.Utils.format;

    format.currency = function (val, sign, decimalPlaces, additionalOptions = {}) {
        const decimalOpts = decimalPlaces !== undefined
            ? {
                minimumFractionDigits: decimalPlaces,
                maximumFractionDigits: decimalPlaces,
            }
            : {
                minimumFractionDigits: 2,
                maximumFractionDigits: 20,
            };

        const opts = {
            style: 'currency',
            currency: sign || Shopware.Context.app.systemCurrencyISOCode,
            currencyDisplay: 'symbol',
            ...decimalOpts,
            ...additionalOptions,
        };

        try {
            const numeric = Number(String(val).replace(',', '.'));
            const formatted = numeric.toLocaleString('de-DE', opts);
            return formatted;
        } catch (e) {
            console.error('[SalesAgent] global comma override error:', e);
            return String(val);
        }
    };

    Shopware.Utils.format = format;
    console.warn('[SalesAgent] Shopware.Utils.format.currency overridden globally (comma decimals)');
}
