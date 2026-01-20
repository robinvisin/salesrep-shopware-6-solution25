import SalesrepPriceEditorPlugin from './plugin/salesrep-price-editor.plugin';
import SalesrepShippingEditorPlugin from './plugin/salesrep-shipping-editor.plugin';
import ZipcodeAutofillPlugin from './plugin/zipcode-autofill.plugin';

const PluginManager = window.PluginManager;
PluginManager.register(
    'ZipcodeAutofillPlugin',
    ZipcodeAutofillPlugin,
    '[data-input-name="zipcodeInput"]'
);
// PluginManager.register(
//   'SalesrepPriceEditorPlugin',
//   SalesrepPriceEditorPlugin,
//   '[data-sa-price-editor]'
// );
// PluginManager.register('SalesrepShippingEditorPlugin', SalesrepShippingEditorPlugin, '[data-sa-shipping-editor]');
