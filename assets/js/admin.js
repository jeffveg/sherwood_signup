/**
 * Sherwood Adventure Tournament System
 * Admin JavaScript
 */

(function() {
    'use strict';

    // ============================================================
    // ADMIN TABS
    // ============================================================
    var adminTabs = document.querySelectorAll('.admin-tab');
    if (adminTabs.length > 0) {
        adminTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var target = this.getAttribute('data-tab');

                // Deactivate all
                document.querySelectorAll('.admin-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                document.querySelectorAll('.admin-tab-panel').forEach(function(p) {
                    p.classList.remove('active');
                });

                // Activate
                this.classList.add('active');
                var panel = document.getElementById('tab-' + target);
                if (panel) {
                    panel.classList.add('active');
                }

                // Update URL hash
                history.replaceState(null, null, '#' + target);
            });
        });

        // Restore tab from URL hash
        var hash = window.location.hash.substring(1);
        if (hash) {
            var tab = document.querySelector('.admin-tab[data-tab="' + hash + '"]');
            if (tab) tab.click();
        }
    }

    // ============================================================
    // SEED UPDATE (AJAX)
    // ============================================================
    window.updateSeed = function(input) {
        var teamId = input.getAttribute('data-team-id');
        var seed = input.value;

        var formData = new FormData();
        formData.append('team_id', teamId);
        formData.append('action', 'update_seed');
        formData.append('seed', seed);

        fetch('/api/team-action.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                input.style.borderColor = 'var(--color-success)';
                setTimeout(function() {
                    input.style.borderColor = '';
                }, 1500);
            }
        })
        .catch(function() {
            input.style.borderColor = 'var(--color-danger)';
        });
    };

    // ============================================================
    // CONFIRMATION MODAL
    // ============================================================
    window.showConfirmModal = function(title, message, onConfirm) {
        var overlay = document.createElement('div');
        overlay.className = 'modal-overlay active';
        overlay.innerHTML = '<div class="modal-box">' +
            '<div class="modal-title">' + title + '</div>' +
            '<div class="modal-body">' + message + '</div>' +
            '<div class="modal-actions">' +
                '<button class="btn btn-secondary btn-small" id="modal-cancel">Cancel</button>' +
                '<button class="btn btn-primary btn-small" id="modal-confirm">Confirm</button>' +
            '</div>' +
        '</div>';

        document.body.appendChild(overlay);

        document.getElementById('modal-cancel').addEventListener('click', function() {
            overlay.remove();
        });

        document.getElementById('modal-confirm').addEventListener('click', function() {
            overlay.remove();
            if (onConfirm) onConfirm();
        });

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.remove();
        });
    };

})();
