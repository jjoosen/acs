$('.js-filter').on('change', function(e) {
    var searchParams = new URLSearchParams(window.location.search);

    if (searchParams.has('sector')) {
        window.location.href = $(this).val() + '?sector=' + encodeURI(searchParams.get('sector'));
    } else {
        window.location.href = $(this).val();
    }
});