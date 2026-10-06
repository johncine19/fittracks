const FT_CSRF_TOKEN = (window.TRAINER_PAGE_CONFIG && window.TRAINER_PAGE_CONFIG.csrfToken)
    || (document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '');

    const ITEMS_PER_PAGE = 6;
    let currentPage = 1;
    let currentFilter = 'all';

    function getMatchingCards() {
        const query = (document.getElementById('tmSearchInput')?.value || '').toLowerCase().trim();
        const cards = Array.from(document.querySelectorAll('.tm-client-item'));
        return cards.filter(card => {
            const name = card.dataset.name || '';
            const email = card.dataset.email || '';
            const goal = card.dataset.goal || '';
            const hasPlan = card.dataset.hasPlan === '1';

            const matchesQuery = !query || name.includes(query) || email.includes(query) || goal.includes(query);
            let matchesFilter = true;
            if (currentFilter === 'has_plan') matchesFilter = hasPlan;
            else if (currentFilter === 'needs_plan') matchesFilter = !hasPlan;

            return matchesQuery && matchesFilter;
        });
    }

    function renderPagination(totalItems) {
        const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const startIdx = (currentPage - 1) * ITEMS_PER_PAGE;
        const endIdx = Math.min(startIdx + ITEMS_PER_PAGE, totalItems);

        const info = document.getElementById('tmPaginationInfo');
        if (info) {
            if (totalItems === 0) {
                info.textContent = 'Showing 0 clients';
            } else {
                info.textContent = `Showing ${startIdx + 1} - ${endIdx} of ${totalItems} client${totalItems === 1 ? '' : 's'}`;
            }
        }

        const prevBtn = document.getElementById('tmPrevBtn');
        if (prevBtn) prevBtn.disabled = (currentPage <= 1);

        const nextBtn = document.getElementById('tmNextBtn');
        if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);

        const pageNumbers = document.getElementById('tmPageNumbers');
        if (pageNumbers) {
            pageNumbers.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.type = 'button';
                pageBtn.className = 'tm-page-num' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.onclick = () => changePage(i);
                pageNumbers.appendChild(pageBtn);
            }
        }

        const paginationBar = document.getElementById('tmPaginationBar');
        if (paginationBar) {
            paginationBar.style.display = totalItems > 0 ? 'flex' : 'none';
        }
    }

    function changePage(page) {
        currentPage = page;
        filterClients(false);
        document.getElementById('tmClientsGrid')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setFilter(filterType, btn) {
        currentFilter = filterType;
        document.querySelectorAll('.tm-filter-btn').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        filterClients(true);
    }

    function resetFilters() {
        const input = document.getElementById('tmSearchInput');
        if (input) input.value = '';
        currentFilter = 'all';
        document.querySelectorAll('.tm-filter-btn').forEach(b => {
            if (b.dataset.filter === 'all') b.classList.add('active');
            else b.classList.remove('active');
        });
        filterClients(true);
    }

    function filterClients(resetPage = true) {
        if (resetPage) currentPage = 1;
        const allCards = document.querySelectorAll('.tm-client-item');
        const matching = getMatchingCards();
        const totalMatching = matching.length;

        const startIdx = (currentPage - 1) * ITEMS_PER_PAGE;
        const endIdx = startIdx + ITEMS_PER_PAGE;

        allCards.forEach(card => {
            card.style.display = 'none';
        });

        matching.slice(startIdx, endIdx).forEach(card => {
            card.style.display = 'flex';
        });

        const counter = document.getElementById('tmResultsCount');
        if (counter) {
            counter.textContent = `Showing ${totalMatching} client${totalMatching === 1 ? '' : 's'}`;
        }

        const noResults = document.getElementById('tmNoResults');
        if (noResults) {
            noResults.style.display = (totalMatching === 0 && allCards.length > 0) ? 'flex' : 'none';
        }

        renderPagination(totalMatching);
    }

    function rejectAppointment(assignmentId) {
        Swal.fire({
            title: 'Reject Appointment',
            input: 'textarea',
            inputLabel: 'Reason for rejection (optional)',
            inputPlaceholder: 'Please state your reason...',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: 'var(--danger)',
            background: 'var(--surface)',
            color: 'var(--ink)'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${FT_CSRF_TOKEN}">
                    <input type="hidden" name="action" value="reject_appointment">
                    <input type="hidden" name="assignment_id" value="${assignmentId}">
                    <input type="hidden" name="rejection_reason" value="${result.value || ''}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    // Assessment Modal Controller
    let currentAssessmentMemberId = null;

    function closeTrainerAssessmentModal() {
        const modal = document.getElementById('trainerAssessmentModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    async function openTrainerAssessmentModal(memberUserId, memberName) {
        currentAssessmentMemberId = memberUserId;
        const modal = document.getElementById('trainerAssessmentModal');
        const titleEl = document.getElementById('trainerAssessmentModalTitle');
        const subEl = document.getElementById('trainerAssessmentModalSub');
        const bodyEl = document.getElementById('trainerAssessmentModalBody');

        if (titleEl) titleEl.textContent = 'Assessment: ' + (memberName || 'Client');
        if (subEl) subEl.textContent = 'Update physical profile, targets & assessment for this member.';
        if (bodyEl) {
            bodyEl.innerHTML = `
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:50px 20px;gap:14px;color:var(--muted);text-align:center;">
                    <span class="loader" style="width:30px;height:30px;border:3px solid var(--line);border-bottom-color:var(--lime);border-radius:50%;display:inline-block;animation:rotation 1s linear infinite;"></span>
                    <span style="font-size:13.5px;font-weight:600;color:var(--ink);">Loading assessment profile...</span>
                </div>
            `;
        }

        if (modal) {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        try {
            const resp = await fetch(`index.php?page=trainer_assessment&member_user_id=${memberUserId}&modal=1`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!resp.ok) {
                throw new Error('Failed to load assessment data');
            }

            const html = await resp.text();
            if (bodyEl) {
                bodyEl.innerHTML = html;

                // Re-evaluate script tags so that tab switching and custom select functions run
                const scripts = bodyEl.querySelectorAll('script');
                scripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });

                // Intercept form submission for smooth in-modal saving
                const form = bodyEl.querySelector('form');
                if (form) {
                    form.addEventListener('submit', async function(e) {
                        e.preventDefault();

                        // Call any validator defined by render_member_form
                        if (typeof window.validateStrengthTarget_profile === 'function') {
                            if (!window.validateStrengthTarget_profile()) {
                                if (typeof window.switchProfileTab_profile === 'function') {
                                    window.switchProfileTab_profile('goal');
                                }
                                return;
                            }
                        }

                        const submitBtn = form.querySelector('button[type=submit]');
                        const origBtnHtml = submitBtn ? submitBtn.innerHTML : 'Save Profile';
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<span class="loader" style="width:14px;height:14px;border:2px solid var(--bg);border-bottom-color:transparent;border-radius:50%;display:inline-block;box-sizing:border-box;animation:rotation 1s linear infinite;margin-right:6px;vertical-align:-2px;"></span> Saving...';
                        }

                        try {
                            const formData = new FormData(form);
                            const saveResp = await fetch(`index.php?page=trainer_assessment&member_user_id=${memberUserId}&ajax=1`, {
                                method: 'POST',
                                body: formData,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            });

                            const result = await saveResp.json();
                            if (result.success) {
                                closeTrainerAssessmentModal();
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Assessment Saved',
                                    text: result.message || 'Fitness assessment updated successfully.',
                                    timer: 2000,
                                    showConfirmButton: false
                                });

                                // Update live card vitals if weight or height changed
                                if (result.weight_kg) {
                                    const wEl = document.getElementById(`vital-weight-${memberUserId}`);
                                    if (wEl) wEl.innerHTML = `${result.weight_kg} <small style="font-size:0.75rem;font-weight:normal;color:var(--muted);">kg</small>`;
                                }
                                if (result.height_cm) {
                                    const hEl = document.getElementById(`vital-height-${memberUserId}`);
                                    if (hEl) hEl.innerHTML = `${result.height_cm} <small style="font-size:0.75rem;font-weight:normal;color:var(--muted);">cm</small>`;
                                }
                            } else {
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = origBtnHtml;
                                }
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Validation Error',
                                    text: result.message || 'Invalid measurements provided.'
                                });
                            }
                        } catch (err) {
                            console.error('Error saving assessment:', err);
                            // Fallback: standard submit
                            form.action = `index.php?page=trainer_assessment&member_user_id=${memberUserId}`;
                            form.submit();
                        }
                    });
                }
            }
        } catch (err) {
            console.error(err);
            if (bodyEl) {
                bodyEl.innerHTML = `
                    <div style="padding:30px 20px;text-align:center;color:var(--danger);">
                        <p style="font-weight:700;margin:0 0 8px 0;">Failed to load assessment form.</p>
                        <p style="font-size:12.5px;color:var(--muted);margin:0 0 16px 0;">Please check your connection and try again.</p>
                        <button type="button" class="btn btn-secondary" onclick="openTrainerAssessmentModal(${memberUserId}, ${JSON.stringify(memberName)})">Retry</button>
                    </div>
                `;
            }
        }
    }

    // Delegated click handler and escape key listener for trainer assessment modal
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-trigger-assessment');
        if (btn) {
            e.preventDefault();
            const id = btn.getAttribute('data-member-id');
            const name = btn.getAttribute('data-member-name') || 'Client';
            if (id) {
                openTrainerAssessmentModal(parseInt(id, 10), name);
            }
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeTrainerAssessmentModal();
        }
    });

    // Initialize pagination and filtering on load
    document.addEventListener('DOMContentLoaded', () => {
        filterClients(true);
    });
