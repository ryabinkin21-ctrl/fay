/* ── FAY · hero carousel ──────────────────────────────────────────
   Auto-initialises every .hero-carousel on the page. Each carousel
   needs a .hc-stage child holding .hc-card items. Used by the movies
   home page (index.php) and the series catalog (series.php).        */
(function () {
    function initCarousel(carousel) {
        const stage = carousel.querySelector('.hc-stage');
        if (!stage) return;
        const cards = Array.from(stage.querySelectorAll('.hc-card'));
        const total = cards.length;
        if (total < 2) return;

        let current = 0;
        let timer   = null;

        function apply() {
            cards.forEach((card, i) => {
                let offset = i - current;
                // wrap around for infinite loop
                if (offset >  total / 2) offset -= total;
                if (offset < -total / 2) offset += total;

                const abs = Math.abs(offset);
                let scale, rotY, z, opacity, blur;

                // card width=320px; translateX chosen so edges don't overlap:
                // center half=160px; ±1 half=320*0.70/2=112px → tx≥160+112+12=284, use 295
                // ±2 half=320*0.50/2=80px → tx≥295+112+80+12=499, use 515 (clips at container edge)
                const TX = [0, 295, 515, 640];
                let tx, sign = offset >= 0 ? 1 : -1;

                if (abs === 0) {
                    scale = 1;    rotY = 0;              z = 40; opacity = 1;    blur = 0;
                } else if (abs === 1) {
                    scale = 0.70; rotY = sign * 40;      z = 30; opacity = 0.88; blur = 0;
                } else if (abs === 2) {
                    scale = 0.50; rotY = sign * 55;      z = 20; opacity = 0.45; blur = 1;
                } else {
                    scale = 0.38; rotY = sign * 65;      z = 5;  opacity = 0;    blur = 3;
                }
                tx = sign * (TX[Math.min(abs, 3)]);

                card.style.transform  = `translateX(${tx}px) scale(${scale}) rotateY(${rotY}deg)`;
                card.style.zIndex     = z;
                card.style.opacity    = opacity;
                card.style.filter     = blur ? `blur(${blur}px)` : '';
                card.style.pointerEvents = abs <= 2 ? 'auto' : 'none';
            });
        }

        function next() {
            current = (current + 1) % total;
            apply();
        }

        function startTimer() { clearInterval(timer); timer = setInterval(next, 6000); }
        function stopTimer()  { clearInterval(timer); timer = null; }

        apply();
        startTimer();

        carousel.addEventListener('mouseenter', stopTimer);
        carousel.addEventListener('mouseleave', startTimer);

        // click on non-center card → jump to it
        cards.forEach((card, i) => {
            card.addEventListener('click', function (e) {
                const offset = ((i - current % total) + total) % total;
                const wrapped = offset > total / 2 ? offset - total : offset;
                if (wrapped !== 0) {
                    e.preventDefault();
                    stopTimer();
                    current = i;
                    apply();
                    startTimer();
                }
            });
        });
    }

    function initAll() {
        document.querySelectorAll('.hero-carousel').forEach(initCarousel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
