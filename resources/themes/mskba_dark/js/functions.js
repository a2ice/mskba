import $ from 'jquery';

(() => {
    const actionHandlers = $('[data-handler]');

    actionHandlers.on('click', function() {
        const handlerName = $(this).data('handler');
        const handler = handlers[handlerName];
        let params = [];

        if (handler && typeof handler === 'function') {
            if($(this).data('params')) params = $(this).data('params');
            handler(this, params);
        }
    });
})();

const handlers = {
    toggleClass(h, params) {
        // data-params="open;body" // 1 - class to toggle mandatory, 2 - selector to toggle class on, default is the element itself
        const paramsStr = params || '';
        if(paramsStr) {
            const params = paramsStr.split(';');
            const target = params[1] ? $(params[1]) : $(h);
            target.toggleClass(params[0]);
        }
    }
};