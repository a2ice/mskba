import $ from 'jquery';

let discoveryLoaded = false;
let discoveryLoading = false;

function loadDiscovery() {
    if (discoveryLoaded || discoveryLoading) {
        return;
    }

    discoveryLoading = true;

    window.setTimeout(() => {
        import('./home-event-discovery.js')
            .then(() => {
                discoveryLoaded = true;
            })
            .finally(() => {
                discoveryLoading = false;
            });
    }, 0);
}

$(document).on('modal:opened.homeEventDiscoveryLoader', function (_event, modal) {
    if (modal.find('[data-home-flow="event"]').length) {
        loadDiscovery();
    }
});
