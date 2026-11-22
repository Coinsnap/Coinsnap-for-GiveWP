/* Coinsnap gateway for GiveWP visual builder (v3) forms. No fields: the donor pays on the payment page. */
(function () {
    if (!window.givewp || !window.givewp.gateways || !window.React) {
        return;
    }
    var el = window.React.createElement;
    var gateway = {
        id: 'coinsnap-gateway',
        Fields: function () {
            var description = (gateway.settings && gateway.settings.description) || '';
            return description ? el('p', {className: 'coinsnap-givewp-help-text'}, description) : null;
        }
    };
    window.givewp.gateways.register(gateway);
})();
