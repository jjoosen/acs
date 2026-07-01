$(document).ready(function () {
    $(".various").fancybox({
        maxWidth: 800,
        maxHeight: 600,
        fitToView: false,
        width: '70%',
        height: '70%',
        autoSize: false,
        closeClick: false,
        openEffect: 'none',
        closeEffect: 'none'
    });

    $(".fancybox").fancybox({
        maxWidth: 800,
        maxHeight: 600,
        fitToView: true,
        width: '70%',
        height: '70%',
        autoSize: false,
        closeClick: false,
        openEffect: 'none',
        closeEffect: 'none',
        padding: 5
    });

    $(".showcookiepolicy").click(function (e) {
        e.preventDefault();
        $("#cookie-policy").addClass("open");
    });

    $(".showprivacypolicy").click(function (e) {
        e.preventDefault();
        $("#privacy-policy").addClass("open");
    });

    $(".closeBtn").click(function () {
        $("#cookie-policy").removeClass("open");
        $("#privacy-policy").removeClass("open");
    });



    $(".scroll-image").on("click", function (e) {

        e.preventDefault();

        $('html, body').animate(
            {
                scrollTop: $("#detail-wrapper").offset().top,
            },
            700,
            'linear'
        )

    });

    $(".contact-button-wrapper").on("click", function (e) {
        // e.preventDefault();
    });

    $('.image').slick({
        arrows: false,
        dots: true,
        autoplay: true,
        respondTo: 'min'
    });

    $('#merken-wrapper').slick({
        arrows: false,
        dots: false,
        slidesToShow:10,
        slidesToScroll:5,
        autoplay: true,
        pauseOnHover: false,
        speed: 1000,
        autoplay: true,
        autoplaySpeed: 3500,
        cssEase: 'linear',
        responsive: [
            {
                breakpoint: 1400,
                settings: {
                    slidesToShow: 8,
                    slidesToScroll: 4
                }
            },
            {
                breakpoint: 1100,
                settings: {
                    slidesToShow: 6,
                    slidesToScroll: 3
                }
            },
            {
                breakpoint: 900,
                settings: {
                    slidesToShow: 4,
                    slidesToScroll: 2
                }
            },
            {
                breakpoint: 700,
                settings: {
                    slidesToShow: 3,
                    slidesToScroll: 1
                }
            },
            {
                breakpoint: 500,
                settings: {
                    slidesToShow: 2,
                    slidesToScroll: 2
                }
            }]
    });

    // $('.note a.privacy').on('click', function (e) {
    //     e.preventDefault();
    //     $("#privacy-policy").toggleClass('open');
    // });
    $('a.cookie').on('click', function (e) {
        e.preventDefault();
        // $("#cookie-policy").toggleClass('open');
    });
    
    $('#cookieClose').on('click', function (e) {
        e.preventDefault();
        $('.cookie-banner').fadeOut();
        document.cookie = "legalCookie=true";
    })




    // Handle appear event for animated elements
    if (window.wpPageOffset == null) {
        var wpOffset =90;
    } else {
        var wpOffset =wpPageOffset;
    }

    if (/Android|webOS|iPhone|iPad|iPod|BlackBerry/i.test(navigator.userAgent)) {
        wpOffset = 100;
    }

    $.fn.waypoint.defaults = {
        context: window,
        continuous: true,
        enabled: true,
        horizontal: false,
        offset: 0,
        triggerOnce: false
    };


    // if ($(window).width() > 700) {
        $('.animated').waypoint(function (direction) {
            var elem = $(this);
            var animation = elem.data('animation');
            if (!elem.hasClass('visible') && elem.attr('data-animation') !== undefined) {
                if (elem.attr('data-animation-delay') !== undefined) {
                    var timeout = elem.data('animation-delay');
                    setTimeout(function () {
                        elem.addClass(animation + " visible");
                    }, timeout);
                } else {
                    elem.addClass(elem.data('animation') + " visible");
                }
            }
        }, {
            offset: wpOffset + '%'
        });
    // }
});