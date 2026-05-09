// ===================================
// MOWOLOGY WEBSITE JAVASCRIPT
// ===================================

// Mobile Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            mobileToggle.classList.toggle('active');
        });
    }
});

// Portfolio Filter
document.addEventListener('DOMContentLoaded', function() {
    const filterButtons = document.querySelectorAll('.filter-btn');
    const portfolioItems = document.querySelectorAll('.portfolio-item');
    
    if (filterButtons.length > 0) {
        filterButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Remove active class from all buttons
                filterButtons.forEach(btn => btn.classList.remove('active'));
                // Add active class to clicked button
                this.classList.add('active');
                
                const filter = this.getAttribute('data-filter');
                
                // Filter portfolio items
                portfolioItems.forEach(item => {
                    if (filter === 'all') {
                        item.style.display = 'block';
                        setTimeout(() => {
                            item.style.opacity = '1';
                            item.style.transform = 'scale(1)';
                        }, 10);
                    } else {
                        const categories = item.getAttribute('data-category').split(' ');
                        if (categories.includes(filter)) {
                            item.style.display = 'block';
                            setTimeout(() => {
                                item.style.opacity = '1';
                                item.style.transform = 'scale(1)';
                            }, 10);
                        } else {
                            item.style.opacity = '0';
                            item.style.transform = 'scale(0.8)';
                            setTimeout(() => {
                                item.style.display = 'none';
                            }, 300);
                        }
                    }
                });
            });
        });
    }
});

// Contact Form Handler - Now using FormSubmit.co
document.addEventListener('DOMContentLoaded', function() {
    // Check if form was successfully submitted (URL parameter)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        const contactForm = document.getElementById('contactForm');
        const formSuccess = document.getElementById('formSuccess');
        if (contactForm && formSuccess) {
            contactForm.style.display = 'none';
            formSuccess.style.display = 'block';
            formSuccess.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});

// Smooth Scroll for Anchor Links
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href !== '#' && href.length > 1) {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });
});

// Add scroll effect to header
let lastScroll = 0;
window.addEventListener('scroll', function() {
    const header = document.querySelector('.header');
    const currentScroll = window.pageYOffset;
    
    if (currentScroll > 100) {
        header.style.boxShadow = '0 2px 20px rgba(0,0,0,0.15)';
    } else {
        header.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
    }
    
    lastScroll = currentScroll;
});

// Form validation helpers
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validatePhone(phone) {
    const re = /^[\d\s\-\(\)]+$/;
    return re.test(phone) && phone.replace(/\D/g, '').length >= 10;
}

// Add real-time validation to form fields
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('email');
    const phoneInput = document.getElementById('phone');
    
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            if (this.value && !validateEmail(this.value)) {
                this.style.borderColor = '#dc3545';
            } else {
                this.style.borderColor = '';
            }
        });
    }
    
    if (phoneInput) {
        phoneInput.addEventListener('blur', function() {
            if (this.value && !validatePhone(this.value)) {
                this.style.borderColor = '#dc3545';
            } else {
                this.style.borderColor = '';
            }
        });
    }
});

// Animate elements on scroll
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

// Subtle scroll-reveal using IntersectionObserver (adds .is-visible class, no JS opacity override)
document.addEventListener('DOMContentLoaded', function() {
    if (!window.IntersectionObserver) return;
    const targets = document.querySelectorAll('.service-card, .feature, .area-card, .mw-stat');
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    targets.forEach(function(el) { observer.observe(el); });
});

// ── Hero Particles ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('mwHeroParticles');
    if (!container) return;
    const count = 40;
    for (let i = 0; i < count; i++) {
        const p = document.createElement('div');
        p.className = 'mw-hero__particle';
        const height = 12 + Math.random() * 28;
        const left = Math.random() * 100;
        const duration = 2.5 + Math.random() * 3;
        const delay = Math.random() * -4;
        p.style.cssText = 'left:' + left + '%;height:' + height + 'px;animation-duration:' + duration + 's;animation-delay:' + delay + 's;opacity:' + (0.3 + Math.random() * 0.5);
        container.appendChild(p);
    }
});

// ── Hero Parallax ────────────────────────────────────
(function() {
    const heroBg = document.querySelector('.mw-hero__bg');
    if (!heroBg) return;
    let ticking = false;
    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(function() {
                const scrollY = window.pageYOffset;
                heroBg.style.transform = 'translateY(' + (scrollY * 0.25) + 'px)';
                ticking = false;
            });
            ticking = true;
        }
    }, { passive: true });
})();

// ── Testimonials Carousel ────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const wrap = document.getElementById('mwTestiWrap');
    const track = document.getElementById('mwTestiTrack');
    const dotsEl = document.getElementById('mwTestiDots');
    const prevBtn = document.getElementById('mwTestiPrev');
    const nextBtn = document.getElementById('mwTestiNext');
    if (!wrap || !track || !dotsEl) return;

    const cards = Array.from(track.querySelectorAll('.mw-testi-card'));
    const cardCount = cards.length;
    if (cardCount === 0) return;

    let current = 0;
    let isDragging = false;
    let dragStartX = 0;
    let dragOffsetX = 0;
    let currentTranslate = 0;

    // Build dots
    cards.forEach(function(_, i) {
        const dot = document.createElement('button');
        dot.className = 'mw-testi-dot' + (i === 0 ? ' is-active' : '');
        dot.setAttribute('aria-label', 'Go to testimonial ' + (i + 1));
        dot.addEventListener('click', function() { goTo(i); });
        dotsEl.appendChild(dot);
    });

    function getCardWidth() {
        const card = cards[0];
        if (!card) return 360;
        return card.offsetWidth + parseInt(getComputedStyle(track).gap || '24', 10);
    }

    function getTrackOffset() {
        const style = getComputedStyle(track);
        return parseInt(style.paddingLeft || '24', 10);
    }

    function goTo(index) {
        current = Math.max(0, Math.min(index, cardCount - 1));
        const offset = getTrackOffset() + current * getCardWidth();
        currentTranslate = -(current * getCardWidth());
        track.style.transition = 'transform 0.5s cubic-bezier(0.22, 0.61, 0.36, 1)';
        track.style.transform = 'translateX(' + currentTranslate + 'px)';
        Array.from(dotsEl.querySelectorAll('.mw-testi-dot')).forEach(function(d, i) {
            d.classList.toggle('is-active', i === current);
        });
    }

    if (prevBtn) prevBtn.addEventListener('click', function() { goTo(current - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function() { goTo(current + 1); });

    // Drag support
    wrap.addEventListener('mousedown', function(e) {
        isDragging = true;
        dragStartX = e.clientX;
        track.style.transition = 'none';
    });
    window.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        dragOffsetX = e.clientX - dragStartX;
        track.style.transform = 'translateX(' + (currentTranslate + dragOffsetX) + 'px)';
    });
    window.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        if (dragOffsetX < -60) goTo(current + 1);
        else if (dragOffsetX > 60) goTo(current - 1);
        else goTo(current);
        dragOffsetX = 0;
    });

    // Touch support
    wrap.addEventListener('touchstart', function(e) {
        dragStartX = e.touches[0].clientX;
        track.style.transition = 'none';
    }, { passive: true });
    wrap.addEventListener('touchmove', function(e) {
        dragOffsetX = e.touches[0].clientX - dragStartX;
        track.style.transform = 'translateX(' + (currentTranslate + dragOffsetX) + 'px)';
    }, { passive: true });
    wrap.addEventListener('touchend', function() {
        if (dragOffsetX < -60) goTo(current + 1);
        else if (dragOffsetX > 60) goTo(current - 1);
        else goTo(current);
        dragOffsetX = 0;
    });
});

// Add loading state to buttons
document.addEventListener('DOMContentLoaded', function() {
    const submitButtons = document.querySelectorAll('button[type="submit"]');
    
    submitButtons.forEach(button => {
        const originalText = button.textContent;
        
        button.addEventListener('click', function() {
            if (!this.disabled) {
                this.disabled = true;
                this.textContent = 'Submitting...';
                
                // Re-enable after form submission or timeout
                setTimeout(() => {
                    this.disabled = false;
                    this.textContent = originalText;
                }, 3000);
            }
        });
    });
});
