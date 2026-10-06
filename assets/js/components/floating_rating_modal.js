/**
 * FitTrack Floating Rating Modal Controller (Member & Owner)
 */
(function initFloatingRatingModals() {
    // 1. Member Floating Modal
    const memberModal = document.getElementById('ft-floating-rating-modal');
    if (memberModal) {
        const userId = memberModal.getAttribute('data-user-id');
        const gymId = memberModal.getAttribute('data-gym-id');
        const storageKey = 'ft_hide_rating_modal_' + userId;
        const sessionKey = 'ft_dismissed_rating_session_' + userId;
        const today = new Date().toISOString().slice(0, 10);

        if (localStorage.getItem(storageKey) !== today && sessionStorage.getItem(sessionKey) !== '1') {
            setTimeout(() => {
                memberModal.classList.add('is-active');
            }, 750);

            const closeBtn = document.getElementById('ft-close-rating-modal');
            const dontShowCheckbox = document.getElementById('ft-dont-show-rating-today');
            const actionBtn = document.getElementById('ft-btn-open-gym-review');

            function dismissModal(rememberForToday) {
                memberModal.classList.remove('is-active');
                sessionStorage.setItem(sessionKey, '1');
                if (rememberForToday || (dontShowCheckbox && dontShowCheckbox.checked)) {
                    localStorage.setItem(storageKey, today);
                }
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    dismissModal(dontShowCheckbox && dontShowCheckbox.checked);
                });
            }

            if (dontShowCheckbox) {
                dontShowCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        localStorage.setItem(storageKey, today);
                    } else {
                        localStorage.removeItem(storageKey);
                    }
                });
            }

            if (actionBtn) {
                actionBtn.addEventListener('click', function() {
                    localStorage.setItem(storageKey, today);
                });
            }

            const quickStars = memberModal.querySelectorAll('.ft-modal-quick-star');
            quickStars.forEach(btn => {
                btn.addEventListener('click', function() {
                    const rating = parseInt(this.getAttribute('data-rating'), 10);
                    if (!rating) return;
                    localStorage.setItem(storageKey, today);
                    window.location.href = 'index.php?page=view_gym&gym_id=' + gymId + '&rating=' + rating + '#gym-ratings-section';
                });
            });
        }
    }

    // 2. Owner Floating Modal
    const ownerModal = document.getElementById('ft-owner-floating-rating-modal');
    if (ownerModal) {
        const userId = ownerModal.getAttribute('data-user-id');
        const storageKey = 'ft_hide_owner_rating_modal_' + userId;
        const sessionKey = 'ft_dismissed_owner_rating_session_' + userId;
        const today = new Date().toISOString().slice(0, 10);

        if (localStorage.getItem(storageKey) !== today && sessionStorage.getItem(sessionKey) !== '1') {
            setTimeout(() => {
                ownerModal.classList.add('is-active');
            }, 750);

            const closeBtn = document.getElementById('ft-close-owner-rating-modal');
            const dontShowCheckbox = document.getElementById('ft-owner-dont-show-rating-today');
            const actionBtn = document.getElementById('ft-btn-open-owner-review');

            function dismissOwnerModal(rememberForToday) {
                ownerModal.classList.remove('is-active');
                sessionStorage.setItem(sessionKey, '1');
                if (rememberForToday || (dontShowCheckbox && dontShowCheckbox.checked)) {
                    localStorage.setItem(storageKey, today);
                }
            }

            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    dismissOwnerModal(dontShowCheckbox && dontShowCheckbox.checked);
                });
            }

            if (dontShowCheckbox) {
                dontShowCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        localStorage.setItem(storageKey, today);
                    } else {
                        localStorage.removeItem(storageKey);
                    }
                });
            }

            if (actionBtn) {
                actionBtn.addEventListener('click', function() {
                    localStorage.setItem(storageKey, today);
                });
            }

            const quickStars = ownerModal.querySelectorAll('.ft-owner-modal-quick-star');
            quickStars.forEach(btn => {
                btn.addEventListener('click', function() {
                    const rating = parseInt(this.getAttribute('data-rating'), 10);
                    if (!rating) return;
                    localStorage.setItem(storageKey, today);
                    window.location.href = 'index.php?page=profile&tab=ratings_feedback&rating=' + rating + '#platform-feedback-card';
                });
            });
        }
    }
})();
