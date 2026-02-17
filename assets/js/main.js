/**
 * Sherwood Adventure Tournament System
 * Main JavaScript
 */

(function() {
    'use strict';

    // ============================================================
    // MOBILE NAV TOGGLE
    // ============================================================
    var navToggle = document.querySelector('.nav-toggle');
    var navLinks = document.querySelector('.nav-links');

    if (navToggle && navLinks) {
        navToggle.addEventListener('click', function() {
            navLinks.classList.toggle('open');
        });

        // Close nav when clicking outside
        document.addEventListener('click', function(e) {
            if (!navToggle.contains(e.target) && !navLinks.contains(e.target)) {
                navLinks.classList.remove('open');
            }
        });
    }

    // ============================================================
    // TWO-STAGE TABS (public tournament view)
    // ============================================================
    var stageTabs = document.querySelectorAll('.two-stage-tab');
    if (stageTabs.length > 0) {
        stageTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var stage = this.getAttribute('data-stage');

                // Deactivate all tabs and panels
                document.querySelectorAll('.two-stage-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                document.querySelectorAll('.stage-panel').forEach(function(p) {
                    p.classList.remove('active');
                });

                // Activate clicked tab and corresponding panel
                this.classList.add('active');
                var panel = document.getElementById('stage-' + stage);
                if (panel) {
                    panel.classList.add('active');
                }
            });
        });
    }

    // ============================================================
    // FLASH MESSAGE AUTO-DISMISS
    // ============================================================
    var flashMessages = document.querySelectorAll('.flash-message');
    flashMessages.forEach(function(msg) {
        setTimeout(function() {
            msg.style.transition = 'opacity 0.5s ease';
            msg.style.opacity = '0';
            setTimeout(function() {
                msg.remove();
            }, 500);
        }, 5000);
    });

    // ============================================================
    // FADE-IN ANIMATION ON SCROLL
    // ============================================================
    var fadeElements = document.querySelectorAll('.fade-in');
    if (fadeElements.length > 0 && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.style.animationPlayState = 'running';
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        fadeElements.forEach(function(el) {
            el.style.animationPlayState = 'paused';
            observer.observe(el);
        });
    }

})();
