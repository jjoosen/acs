if ($('.js-quote-slider').length) {
    import('@splidejs/splide').then(({default: Splide}) => {
        const slider = new Splide('.js-quote-slider', {
            type: 'fade',
            pagination: false,
            arrows: false,
            rewind: true
        }).mount();

        $('.js-quote-slider-btn').on('click', function(e) {
            e.preventDefault();
            slider.go($(this).data('go'));
        })
    });
}
