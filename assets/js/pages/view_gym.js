    function toggleAllEquipment(totalCount) {
        const extraItems = document.querySelectorAll('.gym-equip-extra');
        const btn = document.getElementById('btnToggleEquip');
        if (!btn || !extraItems.length) return;

        const isExpanded = btn.getAttribute('data-expanded') === 'true';
        if (isExpanded) {
            extraItems.forEach(el => el.style.display = 'none');
            btn.setAttribute('data-expanded', 'false');
            btn.innerHTML = '<span>View all ' + totalCount + ' equipment</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>';
        } else {
            extraItems.forEach(el => el.style.display = 'flex');
            btn.setAttribute('data-expanded', 'true');
            btn.innerHTML = '<span>Show less</span> <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>';
        }
    }

    // Star Picker Handler
    (function initStarPicker() {
        const starPicker = document.getElementById('starPickerContainer');
        if (!starPicker) return;
        const starBtns = starPicker.querySelectorAll('.star-btn');
        const hiddenInput = document.getElementById('selectedGymRating');
        const pickerLabel = document.getElementById('starPickerLabel');

        function updateStarDisplay(val) {
            starBtns.forEach(btn => {
                const bVal = parseInt(btn.dataset.val, 10);
                btn.classList.toggle('active', bVal <= val);
            });
            if (pickerLabel) {
                pickerLabel.textContent = val + ' / 5 Stars';
            }
        }

        starBtns.forEach(btn => {
            btn.addEventListener('mouseenter', () => {
                const hoverVal = parseInt(btn.dataset.val, 10);
                starBtns.forEach(b => {
                    const bVal = parseInt(b.dataset.val, 10);
                    b.classList.toggle('hovered', bVal <= hoverVal);
                });
            });

            btn.addEventListener('mouseleave', () => {
                starBtns.forEach(b => b.classList.remove('hovered'));
            });

            btn.addEventListener('click', () => {
                const clickVal = parseInt(btn.dataset.val, 10);
                if (hiddenInput) hiddenInput.value = clickVal;
                updateStarDisplay(clickVal);
            });
        });
    })();

    // Filter Reviews
    function filterReviews(star, btn) {
        document.querySelectorAll('.review-filter-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');

        const items = document.querySelectorAll('.gym-review-item');
        items.forEach(item => {
            const itemRating = parseInt(item.dataset.rating, 10);
            if (star === 'all' || itemRating === parseInt(star, 10)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
