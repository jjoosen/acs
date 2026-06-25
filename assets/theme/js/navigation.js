const logoNav = document.querySelector("#logo-nav")
const logoNavWhite = document.querySelector("#logo-nav-white")
const nav = document.querySelector(".main-nav-trans__container")
// ----------
// mobile nav
// ----------
$('body').on('click', '.js-hamburger', function (e) {
    e.preventDefault();
    $('.js-hamburger').toggleClass('is-active');
    $('.js-nav').toggleClass('is-open');
    $('body').toggleClass('nav-open');

    const jsNav = document.querySelector(".js-nav");
    const isOpen = "is-open";
    const body = document.body;

    if (jsNav.classList.contains(isOpen)) {
        body.setAttribute("style", "overflow-y: hidden;");
        if (logoNavWhite) {
            logoNav.classList.remove("d-none")
            logoNavWhite.classList.add("d-none")
        }
        if (nav) {
            nav.classList.remove("main-nav-trans__container")
            nav.classList.add("main-nav__container")
        }
    } else {
        body.setAttribute("style", "overflow-y: scroll;");
        if (logoNavWhite) {
            logoNav.classList.add("d-none")
            logoNavWhite.classList.remove("d-none")
        }
        if (nav) {
            nav.classList.add("main-nav-trans__container")
            nav.classList.remove("main-nav__container")
        }
    }
});

// ------------------
// anchor navigation
// ------------------

// Smooth scroll when clicking on link to same-page-element
$('.js-side-menu-item').on('click', function (e) {
    var href = $(this).find('a').attr('href');
    var offset = $(href).offset().top;

    $('html, body').stop().animate({
        scrollTop: offset - 50
    }, 600);

    $('.js-side-menu-item').removeClass('is-active');
    $(this).addClass('is-active');

    e.preventDefault();
});

var anchorItems = $(".js-anchor-item");

if (anchorItems.length) {
    activeState();
    window.addEventListener('scroll', throttle(activeState, 250));
}

function throttle(fn, wait) {
    var time = Date.now();
    return function () {
        if ((time + wait - Date.now()) < 0) {
            fn();
            time = Date.now();
        }
    }
}

function activeState() {
    var fromTop = $(window).scrollTop();
    var cur;

    $(".js-anchor-item").each(function () {
        if ($(this).offset().top > fromTop) {
            cur = $(this);
            return false;
        }
    });

    var currentId = cur.attr('id')

    $('.js-side-menu-item').removeClass('is-active');
    $('.js-side-menu-item[data-item="' + currentId + '"]').addClass('is-active');
}