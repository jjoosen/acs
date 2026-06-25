if($('[data-fancybox]').length) {
    import('@fancyapps/fancybox').then(({}) => {
        $('[data-fancybox]').fancybox({
            buttons: [
                "close"
            ],
        });
    });
}
