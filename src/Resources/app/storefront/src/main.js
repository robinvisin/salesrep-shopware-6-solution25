import SalesAgentPriceEditorPlugin from './plugin/sales-agent-price-editor.plugin';
import SalesAgentShippingEditorPlugin from './plugin/sales-agent-shipping-editor.plugin';
import ZipcodeAutofillPlugin from './plugin/zipcode-autofill.plugin';
import SalesAgentSplitCommission from './plugin/sales-agent-split-commission.plugin';

const PluginManager = window.PluginManager;
PluginManager.register(
    'ZipcodeAutofillPlugin',
    ZipcodeAutofillPlugin,
    '[data-input-name="zipcodeInput"]'
);
PluginManager.register('SalesAgentSplitCommission', SalesAgentSplitCommission, 'body');
// PluginManager.register(
//   'SalesAgentPriceEditorPlugin',
//   SalesAgentPriceEditorPlugin,
//   '[data-sa-price-editor]'
// );
// PluginManager.register('SalesAgentShippingEditorPlugin', SalesAgentShippingEditorPlugin, '[data-sa-shipping-editor]');
