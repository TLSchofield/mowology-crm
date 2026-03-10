// ===================================
// MOWOLOGY WEBSITE JAVASCRIPT
// ===================================

// ── Mobile Menu Toggle ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var toggle  = document.querySelector('.mobile-menu-toggle');
    var navMenu = document.querySelector('.nav-menu');
    var header  = document.querySelector('.header');

    if (toggle && navMenu) {
        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            var isOpen = navMenu.classList.toggle('active');
            toggle.classList.toggle('active', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (!header.contains(e.target)) {
                navMenu.classList.remove('active');
                toggle.classList.remove('active');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        // Close on nav link click
        navMenu.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                navMenu.classList.remove('active');
                toggle.classList.remove('active');
                toggle.setAttribute('aria-expanded', 'false');
            });
        });
    }
});

// ── Header Scroll Shadow ────────────────────────────
(function() {
    var header = document.querySelector('.header');
    if (!header) return;
    window.addEventListener('scroll', function() {
        header.classList.toggle('header--scrolled', window.scrollY > 80);
    }, { passive: true });
})();

// ── Scroll Reveal (IntersectionObserver) ────────────
document.addEventListener('DOMContentLoaded', function() {
    // New .mw-reveal pattern (enhanced blocks, new sections)
    var revealObs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                e.target.classList.add('is-visible');
                revealObs.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.mw-reveal').forEach(function(el) {
        revealObs.observe(el);
    });

    // Legacy card animation — class-based, no inline styles
    var legacyObs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                e.target.classList.add('anim-visible');
                legacyObs.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.service-card, .feature, .testimonial-card, .portfolio-item, .value-card, .area-card').forEach(function(el) {
        el.classList.add('anim-hidden');
        legacyObs.observe(el);
    });
});

// ── Portfolio Filter ────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var filterButtons = document.querySelectorAll('.filter-btn');
    var portfolioItems = document.querySelectorAll('.portfolio-item');

    if (filterButtons.length === 0) return;

    filterButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            filterButtons.forEach(function(btn) { btn.classList.remove('active'); });
            this.classList.add('active');

            var filter = this.getAttribute('data-filter');
            portfolioItems.forEach(function(item) {
                var categories = (item.getAttribute('data-category') || '').split(' ');
                var show = filter === 'all' || categories.includes(filter);
                if (show) {
                    item.style.display = 'block';
                    setTimeout(function() {
                        item.style.opacity = '1';
                        item.style.transform = 'scale(1)';
                    }, 10);
                } else {
                    item.style.opacity = '0';
                    item.style.transform = 'scale(0.8)';
                    setTimeout(function() {
                        item.style.display = 'none';
                    }, 300);
                }
            });
        });
    });
});

// ── Smooth Scroll ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (href !== '#' && href.length > 1) {
                var target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });
});

// ── Contact Form Success ─────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('success') === '1') {
        var form    = document.getElementById('contactForm');
        var success = document.getElementById('formSuccess');
        if (form && success) {
            form.style.display = 'none';
            success.style.display = 'block';
            success.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});

// ── Form Validation (CSS-class based, no inline styles) ──
function validateEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
function validatePhone(phone) {
    return /^[\d\s\-\(\)]+$/.test(phone) && phone.replace(/\D/g, '').length >= 10;
}
document.addEventListener('DOMContentLoaded', function() {
    var emailInput = document.getElementById('email');
    var phoneInput = document.getElementById('phone');
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            this.classList.toggle('input-error', this.value.length > 0 && !validateEmail(this.value));
        });
        emailInput.addEventListener('input', function() {
            if (this.classList.contains('input-error') && validateEmail(this.value)) {
                this.classList.remove('input-error');
            }
        });
    }
    if (phoneInput) {
        phoneInput.addEventListener('blur', function() {
            this.classList.toggle('input-error', this.value.length > 0 && !validatePhone(this.value));
        });
        phoneInput.addEventListener('input', function() {
            if (this.classList.contains('input-error') && validatePhone(this.value)) {
                this.classList.remove('input-error');
            }
        });
    }
});

// ── Submit Button Loading State ──────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('button[type="submit"]').forEach(function(btn) {
        var originalText = btn.textContent;
        btn.addEventListener('click', function() {
            if (!this.disabled) {
                this.disabled = true;
                this.textContent = 'Submitting…';
                setTimeout(function() {
                    btn.disabled = false;
                    btn.textContent = originalText;
                }, 5000);
            }
        });
    });
});

// ── Hero Module (enhanced) ───────────────────────────
(function() {
    var hero = document.getElementById('mw-hero');
    if (!hero) return;

    // Parallax background
    var bg = hero.querySelector('.mw-hero__bg');
    if (bg) {
        window.addEventListener('scroll', function() {
            if (window.scrollY < window.innerHeight) {
                bg.style.transform = 'translateY(' + (window.scrollY * 0.35) + 'px)';
            }
        }, { passive: true });
    }

    // Grass particles
    var particleContainer = document.getElementById('mw-hero-particles');
    if (particleContainer) {
        for (var i = 0; i < 40; i++) {
            var p = document.createElement('div');
            p.className = 'mw-hero__particle';
            var h = 20 + Math.random() * 80;
            p.style.cssText =
                'left:' + (Math.random() * 100) + '%;' +
                'height:' + h + 'px;' +
                'opacity:' + (0.08 + Math.random() * 0.25) + ';' +
                'animation-duration:' + (2 + Math.random() * 3) + 's;' +
                'animation-delay:' + (Math.random() * 3) + 's;';
            particleContainer.appendChild(p);
        }
    }
})();

// ── Stats Counter ────────────────────────────────────
(function() {
    var statsSection = document.getElementById('mw-stats');
    if (!statsSection) return;

    function animateCounter(el) {
        var target = parseInt(el.getAttribute('data-target'), 10);
        if (isNaN(target) || target === 0) return;
        var duration = 1600;
        var start = performance.now();
        function step(now) {
            var progress = Math.min((now - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.floor(eased * target);
            if (progress < 1) requestAnimationFrame(step);
            else el.textContent = target;
        }
        requestAnimationFrame(step);
    }

    var statsObs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) {
            if (e.isIntersecting) {
                e.target.querySelectorAll('.mw-stat__count').forEach(animateCounter);
                statsObs.unobserve(e.target);
            }
        });
    }, { threshold: 0.3 });
    statsObs.observe(statsSection);
})();

// ── Testimonials Carousel ────────────────────────────
(function() {
    var carousel = document.getElementById('mw-testimonials');
    if (!carousel) return;

    var track     = carousel.querySelector('.mw-testi-track');
    var cards     = carousel.querySelectorAll('.mw-testi-card');
    var prevBtn   = document.getElementById('mw-testi-prev');
    var nextBtn   = document.getElementById('mw-testi-next');
    var dotsWrap  = document.getElementById('mw-testi-dots');
    if (!track || cards.length === 0) return;

    var cardCount = Math.floor(cards.length / 2); // original count (cards doubled for loop)
    var current   = 0;
    var cardWidth = 0;
    var gap       = 24;
    var autoTimer = null;

    function getCardWidth() {
        return cards[0] ? cards[0].offsetWidth : 360;
    }

    function buildDots() {
        if (!dotsWrap) return;
        dotsWrap.innerHTML = '';
        for (var i = 0; i < cardCount; i++) {
            var dot = document.createElement('button');
            dot.className = 'mw-testi-dot' + (i === 0 ? ' is-active' : '');
            dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
            dot.setAttribute('data-index', i);
            dotsWrap.appendChild(dot);
        }
        dotsWrap.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-index]');
            if (btn) goTo(parseInt(btn.getAttribute('data-index'), 10));
        });
    }

    function updateDots() {
        if (!dotsWrap) return;
        dotsWrap.querySelectorAll('.mw-testi-dot').forEach(function(d, i) {
            d.classList.toggle('is-active', i === current % cardCount);
        });
    }

    function goTo(index) {
        current = ((index % cardCount) + cardCount) % cardCount;
        cardWidth = getCardWidth();
        track.style.transform = 'translateX(-' + (current * (cardWidth + gap)) + 'px)';
        updateDots();
        resetAuto();
    }

    function resetAuto() {
        clearInterval(autoTimer);
        autoTimer = setInterval(function() { goTo(current + 1); }, 5000);
    }

    buildDots();
    resetAuto();

    if (prevBtn) prevBtn.addEventListener('click', function() { goTo(current - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function() { goTo(current + 1); });

    // Drag / swipe
    var dragStart = null;
    track.addEventListener('mousedown',  function(e) { dragStart = e.clientX; });
    track.addEventListener('touchstart', function(e) { dragStart = e.touches[0].clientX; }, { passive: true });
    track.addEventListener('mouseup', function(e) {
        if (dragStart === null) return;
        var delta = dragStart - e.clientX;
        if (Math.abs(delta) > 50) goTo(current + (delta > 0 ? 1 : -1));
        dragStart = null;
    });
    track.addEventListener('touchend', function(e) {
        if (dragStart === null) return;
        var delta = dragStart - e.changedTouches[0].clientX;
        if (Math.abs(delta) > 50) goTo(current + (delta > 0 ? 1 : -1));
        dragStart = null;
    });

    // Recalculate on resize
    window.addEventListener('resize', function() { goTo(current); });
})();
