const body = document.body
const nav = document.querySelector(".main-nav")
const logoNav = document.querySelector("#logo-nav")
const logoNavWhite = document.querySelector("#logo-nav-white")
const scrollUp = "scroll-up"
const scrollDown = "scroll-down"
let lastScroll = 0

window.addEventListener("scroll", () => {
  const currentScroll = window.pageYOffset - 160

  if (currentScroll <= 0) {
    body.classList.remove(scrollUp)

    if (logoNav) {
      logoNav.classList.add("d-none")
      logoNavWhite.classList.remove("d-none")
    }
    return
  }

  if (currentScroll > lastScroll && !body.classList.contains(scrollDown)) {
    body.classList.remove(scrollUp)
    body.classList.add(scrollDown)

    if (logoNav) {
      logoNav.classList.remove("d-none")
      logoNavWhite.classList.add("d-none")
    }
  } else if (
    currentScroll < lastScroll &&
    body.classList.contains(scrollDown)
  ) {
    body.classList.remove(scrollDown)
    body.classList.add(scrollUp)
  }

  lastScroll = currentScroll
})
