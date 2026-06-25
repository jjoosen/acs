const body = document.body
const nav = document.querySelector(".main-nav-trans__container")
const navWhite = document.querySelector(".main-nav__container")
const logoNav = document.querySelector("#logo-nav")
const logoNavWhite = document.querySelector("#logo-nav-white")
const navLink = document.querySelectorAll(".main-nav-trans__link")
const scrollUp = "scroll-up"
const scrollDown = "scroll-down"
let lastScroll = 0

window.addEventListener("scroll", () => {
    const currentScroll = window.pageYOffset - 140

    if (currentScroll >= 0) {

        if (logoNav) {
            logoNav.classList.remove("d-none")
            logoNavWhite.classList.add("d-none")
        }
        if (nav) {
            nav.classList.remove("main-nav-trans__container")
            nav.classList.add("main-nav__container")
            nav.classList.add("box-shadow")
        }
        if (navWhite) {
            navWhite.classList.add("box-shadow")
        }
        if (navLink) {
            navLink.forEach(function (el) {
                el.classList.add('main-nav__link');
                el.classList.remove('main-nav-trans__link');
            });
        }
        // return
    } else {
        if (logoNav) {
            logoNav.classList.add("d-none")
            logoNavWhite.classList.remove("d-none")
        }
        if (nav) {
            nav.classList.add("main-nav-trans__container")
            nav.classList.remove("main-nav__container")
            nav.classList.remove("box-shadow")
        }
        if (navWhite) {
            navWhite.classList.remove("box-shadow")
        }
        if (navLink) {

            navLink.forEach(function (el) {
                el.classList.remove('main-nav__link');
                el.classList.add('main-nav-trans__link');
            });
        }
    }
})
