/**
 * Purely decorative page-background particles (self-hosted particles.js, no CDN).
 * Renders into #particles-bg, which is fixed, full-viewport, and sits at z-index -1
 * behind every block (header, Items panel, tag cloud, fish tank) -- since those all
 * have their own solid backgrounds already, the particles only show through the empty
 * space around/between them without any extra "cutout" logic needed.
 */
(function () {
    if (typeof particlesJS === "undefined") return;

    particlesJS("particles-bg", {
        particles: {
            number: { value: 45, density: { enable: true, value_area: 900 } },
            color: { value: "#000000" },
            shape: { type: "circle" },
            opacity: {
                value: 0.35,
                random: true,
                anim: { enable: true, speed: 0.4, opacity_min: 0.08, sync: false },
            },
            size: { value: 3, random: true },
            line_linked: {
                enable: true,
                distance: 130,
                color: "#000000",
                opacity: 0.25,
                width: 1,
            },
            move: {
                enable: true,
                speed: 0.8,
                direction: "none",
                random: true,
                straight: false,
                out_mode: "out",
                bounce: false,
            },
        },
        interactivity: {
            detect_on: "canvas",
            events: {
                onhover: { enable: false },
                onclick: { enable: false },
                resize: true,
            },
        },
        retina_detect: true,
    });
})();
