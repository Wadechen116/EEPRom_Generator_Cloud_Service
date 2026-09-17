/**
 * Lightweight, dependency-free 3D tag cloud (the classic rotating-sphere effect,
 * like old WP-Cumulus/Flash tag clouds, rebuilt with plain CSS transforms + JS --
 * no WebGL, no library). Tags are distributed evenly on a sphere (Fibonacci sphere
 * spacing), auto-rotated every frame; each tag's own (x, y) offset plus scale/opacity
 * are recomputed from its current depth (z), so tags facing the viewer look bigger
 * and more opaque, tags around the back look smaller and fainter.
 *
 * Auto-rotation pauses on hover (so a tag can actually be clicked) and whenever the
 * browser tab isn't visible.
 */
class TagCloud3D {
    constructor(container) {
        this.container = container;
        this.points = [];
        this.angleY = 0;
        this.angleX = 0.2; // fixed tilt so the sphere doesn't look flat from directly above
        this.paused = false;
        this.rafId = null;

        container.addEventListener("mouseenter", () => { this.paused = true; });
        container.addEventListener("mouseleave", () => { this.paused = false; });
        document.addEventListener("visibilitychange", () => {
            if (document.hidden) this.stop();
            else if (this.points.length) this.start();
        });
    }

    /** tags: array of [value, count] pairs, most-frequent first. */
    setTags(tags) {
        this.stop();
        this.container.innerHTML = "";

        const n = tags.length;
        this.points = tags.map(([value, count], i) => {
            const el = document.createElement("button");
            el.type = "button";
            el.className = "tag-pill";
            el.textContent = value;
            el.dataset.value = value;
            el.title = `${count} item(s)`;
            this.container.appendChild(el);

            // Fibonacci sphere: evenly spaced points over a sphere's surface.
            const y = n > 1 ? 1 - (i / (n - 1)) * 2 : 0;
            const r = Math.sqrt(Math.max(0, 1 - y * y));
            const theta = i * 2.399963; // golden angle, radians
            return { bx: Math.cos(theta) * r, by: y, bz: Math.sin(theta) * r, el };
        });

        if (n > 0) {
            this.render();
            this.start();
        }
    }

    currentRadius() {
        const w = this.container.clientWidth || 200;
        const h = this.container.clientHeight || 190;
        return Math.max(50, Math.min(w, h) * 0.42);
    }

    start() {
        if (this.rafId || this.points.length === 0) return;
        const tick = () => {
            if (!this.paused) {
                this.angleY += 0.006;
                this.render();
            }
            this.rafId = requestAnimationFrame(tick);
        };
        this.rafId = requestAnimationFrame(tick);
    }

    stop() {
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }

    render() {
        const radius = this.currentRadius();
        const cosY = Math.cos(this.angleY), sinY = Math.sin(this.angleY);
        const cosX = Math.cos(this.angleX), sinX = Math.sin(this.angleX);

        this.points.forEach((p) => {
            // Rotate around the Y axis (continuous spin), then a fixed X-axis tilt.
            const x = p.bx * cosY + p.bz * sinY;
            const zAfterY = -p.bx * sinY + p.bz * cosY;
            const y = p.by * cosX - zAfterY * sinX;
            const z = p.by * sinX + zAfterY * cosX;

            const depth = (z + 1) / 2; // 0 = far side, 1 = near side
            const scale = 0.65 + depth * 0.6;
            const opacity = 0.3 + depth * 0.7;

            p.el.style.transform =
                `translate(-50%, -50%) translate3d(${(x * radius).toFixed(1)}px, ${(y * radius).toFixed(1)}px, 0) scale(${scale.toFixed(3)})`;
            p.el.style.opacity = opacity.toFixed(2);
            p.el.style.zIndex = Math.round(depth * 100);
        });
    }
}
