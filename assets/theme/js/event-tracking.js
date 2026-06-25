/**
 * NOTE: this script uses jQuery to track certain click events.
 * If your project does not use jQuery, you will have to trigger these events manually!
 * Make sure you use the correct function ('ga' or 'gtag'), according to your project's needs!
 *
 * e.g.:
 * <a href="tel:+123456" onclick="gtag('event', 'tel-click', { 'event_category': 'telefoon', 'event_label': window.location.pathname });">+123456</a>
 * <a href="mailto:john@doe.com" onclick="gtag('event', 'mail-click', { 'event_category': 'mail', 'event_label': window.location.pathname });">john@doe.com</a>
 * <a href="https://www.google.be/maps/dir/..." onclick="gtag('event', 'route-click', { 'event_category': 'routebeschrijving', 'event_label': window.location.pathname });">Routebeschrijving</a>
 */
if(typeof $ !== 'undefined') {
    // Track mailto: click events
    $("a[href^='mailto:']").on('click', function(e) {
        e.preventDefault();
        var href = $(this).attr('href');
        // Trim whitespace
        var value = $(this).text().replace(/^\s+|\s+$/gm,'');

        fireEvent('mail', 'mail-click', value);

        setTimeout(function() {
            window.location = href;
        }, 500);
    });

    // Track tel: click events
    $("a[href^='tel:']").on('click', function(e) {
        e.preventDefault();
        var href = $(this).attr('href');
        // Trim whitespace
        var value = $(this).text().replace(/^\s+|\s+$/gm,'');

        fireEvent('phone', 'tel-click', value);

        setTimeout(function() {
            window.location = href;
        }, 500);
    });

    // Track directions click events
    $("a[href^='https://www.google.be/maps/dir'], .js-directions").on('click', function(e) {
        e.preventDefault();
        var href = $(this).attr('href');

        fireEvent('directions', 'route-click', window.location.pathname);

        setTimeout(function() {
            window.location = href;
        }, 500);
    });

    function fireEvent(category, action, label) {
        if (typeof gtag !== "undefined") {
            gtag(
                'event',
                action, {
                    'event_category': category,
                    'event_label': label
                }
            );
        } else if (typeof ga !== "undefined") {
            var prefix = ga.getAll().map(t => t.get('name'));
            ga(
                prefix + '.send',
                'event',
                category, // Category
                action, // Action
                label, // Label
            );
        }
    }
}
