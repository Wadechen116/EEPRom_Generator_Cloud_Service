/**
 * Purely decorative: a few soft, semi-transparent fish swimming in the small tank at
 * the bottom of the Items panel. Self-contained (doesn't touch API_KEY/auth/app state).
 * Kept lightweight for an admin page: capped frame rate, and the draw loop pauses
 * whenever the browser tab isn't visible.
 */
(function () {
    const container = document.getElementById("fishTank");
    if (!container || typeof p5 === "undefined") return;

    new p5((sk) => {
        const FISH_COUNT = 5;
        const COLORS = [
            [255, 255, 255],
            [179, 219, 255],
            [190, 240, 225],
            [255, 224, 189],
            [222, 200, 255],
        ];
        let fish = [];

        function spawnFish() {
            fish = [];
            for (let i = 0; i < FISH_COUNT; i++) {
                fish.push({
                    x: sk.random(sk.width),
                    y: sk.random(sk.height * 0.15, sk.height * 0.85),
                    size: sk.random(14, 24),
                    speed: sk.random(0.35, 0.9),
                    dir: sk.random() < 0.5 ? -1 : 1,
                    wiggle: sk.random(1000),
                    color: sk.random(COLORS),
                });
            }
        }

        sk.setup = () => {
            const c = sk.createCanvas(container.clientWidth || 220, container.clientHeight || 140);
            c.parent(container);
            sk.frameRate(24);
            spawnFish();
        };

        sk.draw = () => {
            sk.clear();
            fish.forEach((f) => {
                f.x += f.speed * f.dir;
                f.wiggle += 0.15;
                if (f.x > sk.width + 20) f.x = -20;
                if (f.x < -20) f.x = sk.width + 20;

                const bob = Math.sin(f.wiggle) * 4;
                sk.push();
                sk.translate(f.x, f.y + bob);
                sk.scale(f.dir, 1);
                sk.noStroke();
                sk.fill(f.color[0], f.color[1], f.color[2], 165);

                // body
                sk.ellipse(0, 0, f.size, f.size * 0.52);
                // tail (wags as it swims)
                const wag = Math.sin(f.wiggle * 1.6) * 6;
                sk.triangle(
                    -f.size * 0.42, 0,
                    -f.size * 0.8, -f.size * 0.26 + wag,
                    -f.size * 0.8, f.size * 0.26 + wag
                );
                sk.pop();
            });
        };

        sk.windowResized = () => {
            sk.resizeCanvas(container.clientWidth || 220, container.clientHeight || 140);
        };

        document.addEventListener("visibilitychange", () => {
            if (document.hidden) sk.noLoop();
            else sk.loop();
        });
    });
})();
