import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

function animateFrom(elem, direction = 1, delay = 0) {
    var x = 0,
        y = direction * 64;
    if(elem.dataset.from === 'left') {
        x = -100;
        y = 0;
    } else if (elem.dataset.from === 'right') {
        x = 100;
        y = 0;
    }
    elem.style.transform = "translate(" + x + "px, " + y + "px)";
    elem.style.opacity = "0";

    gsap.fromTo(elem, {x: x, y: y, autoAlpha: 0}, {
        duration: 1.25,
        x: 0,
        y: 0,
        autoAlpha: 1,
        ease: "expo",
        overwrite: "auto",
        delay: delay
    });
}

function hide(elem) {
    gsap.set(elem, {autoAlpha: 0});
}

document.addEventListener("DOMContentLoaded", function() {
    gsap.registerPlugin(ScrollTrigger);

    gsap.utils.toArray(".js-scroll-reveal").forEach(function(elem) {
        hide(elem); // assure that the element is hidden when scrolled into view

        ScrollTrigger.create({
            trigger: elem,
            onEnter: function() { animateFrom(elem) },
            onEnterBack: function() { animateFrom(elem, -1) },
            onLeave: function() { hide(elem) } // assure that the element is hidden when scrolled into view
        });
    });

    gsap.utils.toArray(".js-scroll-reveal-children").forEach((parent) => {
        Array.from(parent.children).forEach((elem, key) => {
            hide(elem); // assure that the element is hidden when scrolled into view

            const delay = key * 0.1;

            ScrollTrigger.create({
                trigger: elem,
                onEnter: function() { animateFrom(elem, 1, delay) },
                onEnterBack: function() { animateFrom(elem, -1) },
                onLeave: function() { hide(elem) } // assure that the element is hidden when scrolled into view
            });
        })
    });
});
