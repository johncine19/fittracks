/**
 * FitTracks - Walk-In Front Desk Terminal
 * assets/js/pages/walk_ins.js
 */

document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // Member Conversion Modal Controller
    // -------------------------------------------------------------
    window.openConvertModal = function (transactionId, firstName, lastName, phone) {
        const idInput = document.getElementById('convert_transaction_id');
        const firstInput = document.getElementById('convert_first_name');
        const lastInput = document.getElementById('convert_last_name');
        const phoneInput = document.getElementById('convert_phone');
        const modal = document.getElementById('convertModal');

        if (idInput) idInput.value = transactionId;
        if (firstInput) firstInput.value = firstName || '';
        if (lastInput) lastInput.value = lastName || '';
        if (phoneInput) phoneInput.value = phone || '';
        if (modal) modal.showModal();
    };

    // Event delegation for Member Conversion modal button triggers
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-convert-member');
        if (btn) {
            openConvertModal(
                parseInt(btn.getAttribute('data-id'), 10),
                btn.getAttribute('data-first') || '',
                btn.getAttribute('data-last') || '',
                btn.getAttribute('data-phone') || ''
            );
        }
    });

    // -------------------------------------------------------------
    // Real-Time Instant Filter & Search Engine (Zero Reload & Zero Jump)
    // -------------------------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const initialFilter = urlParams.get('filter') || 
                          document.getElementById('walkInFilterDropdown')?.value || 
                          document.getElementById('filterInput')?.value || 
                          'all';

    let currentFilter = initialFilter;

    function applyWalkInFilters() {
        const query = (document.getElementById('walkInSearchInput')?.value || '').trim().toLowerCase();
        const rows = document.querySelectorAll('#walkInTableBody .fit-table-row');
        const mobileCards = document.querySelectorAll('.fit-mobile-cards .fit-mobile-ticket');

        let visibleCount = 0;

        function checkMatches(el) {
            const searchData = el.getAttribute('data-search') || '';
            const status = el.getAttribute('data-status') || '';
            const method = el.getAttribute('data-method') || '';

            const matchesQuery = !query || searchData.includes(query);

            let matchesFilter = true;
            if (currentFilter === 'walkin') {
                matchesFilter = (status === 'walkin');
            } else if (currentFilter === 'converted') {
                matchesFilter = (status === 'converted');
            } else if (currentFilter === 'cash') {
                matchesFilter = (method === 'cash');
            } else if (currentFilter === 'gcash') {
                matchesFilter = (method === 'gcash');
            }

            return matchesQuery && matchesFilter;
        }

        rows.forEach(function (row) {
            const show = checkMatches(row);
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        mobileCards.forEach(function (card) {
            const show = checkMatches(card);
            card.style.display = show ? '' : 'none';
        });

        const counter = document.getElementById('fitRecordCounter');
        if (counter) {
            const total = rows.length;
            counter.innerHTML = 'Showing <strong>' + visibleCount + '</strong> of <strong>' + total + '</strong> entries';
        }
    }

    function setFilter(filterVal) {
        currentFilter = filterVal;

        // Update desktop chips UI
        document.querySelectorAll('.fit-filter-chips .fit-chip').forEach(function (c) {
            if (c.getAttribute('data-filter') === filterVal) {
                c.classList.add('is-active');
            } else {
                c.classList.remove('is-active');
            }
        });

        // Update mobile dropdown UI
        const mobileSelect = document.getElementById('walkInFilterDropdown');
        if (mobileSelect && mobileSelect.value !== filterVal) {
            mobileSelect.value = filterVal;
        }

        // Update hidden input in search form
        const filterInput = document.getElementById('filterInput');
        if (filterInput) filterInput.value = filterVal;

        // Update URL query parameter silently without reloading page or jumping to top
        try {
            const url = new URL(window.location.href);
            if (filterVal === 'all') {
                url.searchParams.delete('filter');
            } else {
                url.searchParams.set('filter', filterVal);
            }
            window.history.replaceState(null, '', url.toString());
        } catch (err) {}

        applyWalkInFilters();
    }

    // Attach desktop chip clicks
    document.querySelectorAll('.fit-filter-chips .fit-chip').forEach(function (chip) {
        chip.addEventListener('click', function (e) {
            e.preventDefault();
            setFilter(this.getAttribute('data-filter') || 'all');
        });
    });

    // Attach mobile dropdown change
    const mobileSelect = document.getElementById('walkInFilterDropdown');
    if (mobileSelect) {
        mobileSelect.addEventListener('change', function () {
            setFilter(this.value);
        });
    }

    // Attach instant search input
    const searchInput = document.getElementById('walkInSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            applyWalkInFilters();
        });
    }

    // Run initial filter if loaded with a specific filter param
    if (currentFilter !== 'all') {
        applyWalkInFilters();
    }
});
