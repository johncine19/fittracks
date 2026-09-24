/**
 * FitTracks Global Custom Combobox & Dropdown Component
 * Modern, accessible, theme-adaptive dropdown helper.
 */
(function(window) {
    'use strict';

    const instances = new Set();

    function safeEscape(str) {
        if (typeof window.escapeHtml === 'function') {
            return window.escapeHtml(str);
        }
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // Global document click listener for outside clicks
    document.addEventListener('click', function(e) {
        instances.forEach(inst => {
            if (inst.wrap && !inst.wrap.contains(e.target)) {
                inst.close();
            }
        });
    });

    // Global keyboard listener for Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            instances.forEach(inst => inst.close());
        }
    });

    class FitDropdown {
        constructor(options = {}) {
            this.options = Object.assign({
                container: null,
                name: '',
                id: '',
                value: null,
                placeholder: 'Select...',
                searchable: false,
                searchPlaceholder: 'Search...',
                items: [],
                required: false,
                zIndex: 50,
                allowClear: false,
                onChange: null,
                onSearch: null
            }, options);

            this.wrap = typeof this.options.container === 'string'
                ? document.querySelector(this.options.container)
                : this.options.container;

            if (!this.wrap) {
                console.error('[FitDropdown] Container not found:', this.options.container);
                return;
            }

            this.items = (Array.isArray(this.options.items) ? this.options.items : []).map(i => this._normalizeItem(i));
            this.selectedItem = null;
            this.activeIndex = -1;
            this.isOpen = false;
            this.baseZIndex = this.options.zIndex || 50;

            this._initDOM();
            this._bindEvents();

            if (this.options.value !== null && this.options.value !== undefined && this.options.value !== '') {
                this.select(this.options.value, false);
            } else {
                this._renderTrigger();
                this._renderList();
            }

            instances.add(this);
        }

        static closeAll() {
            instances.forEach(inst => inst.close());
        }

        _normalizeItem(item) {
            if (!item || typeof item !== 'object') return item;
            return Object.assign({}, item, {
                id: item.id !== undefined ? item.id : item.value,
                label: item.label !== undefined ? item.label : (item.name !== undefined ? item.name : ''),
                subtitle: item.subtitle !== undefined ? item.subtitle : (item.email !== undefined ? item.email : ''),
                price: item.price !== undefined ? item.price : (item.formatted_price !== undefined ? item.formatted_price : '')
            });
        }

        _initDOM() {
            this.wrap.classList.add('fit-dropdown-wrap');
            this.wrap.style.zIndex = this.baseZIndex;

            const inputId = this.options.id || ('fit_drop_' + Math.random().toString(36).substr(2, 9));
            const inputName = this.options.name || '';
            const isReq = this.options.required ? 'required' : '';

            let searchHtml = '';
            if (this.options.searchable) {
                searchHtml = `
                    <div class="fit-dropdown-search-wrap">
                        <div class="fit-dropdown-search-box">
                            <svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" class="fit-dropdown-search-input" placeholder="${safeEscape(this.options.searchPlaceholder)}" autocomplete="off">
                            <span class="fit-dropdown-search-badge"></span>
                        </div>
                    </div>
                `;
            }

            this.wrap.innerHTML = `
                <input type="hidden" name="${safeEscape(inputName)}" id="${safeEscape(inputId)}" value="" ${isReq}>
                <div class="fit-dropdown-trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                    <div class="fit-dropdown-trigger-content"></div>
                    <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                        ${this.options.allowClear ? `
                            <button type="button" class="fit-dropdown-clear-btn" style="display:none; background:none; border:none; padding:2px; cursor:pointer; color:var(--muted); line-height:1; border-radius:50%;" title="Clear">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        ` : ''}
                        <svg class="fit-dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                </div>
                <div class="fit-dropdown-menu">
                    ${searchHtml}
                    <div class="fit-dropdown-list" role="listbox"></div>
                </div>
            `;

            this.input = this.wrap.querySelector('input[type="hidden"]');
            this.trigger = this.wrap.querySelector('.fit-dropdown-trigger');
            this.triggerContent = this.wrap.querySelector('.fit-dropdown-trigger-content');
            this.clearBtn = this.wrap.querySelector('.fit-dropdown-clear-btn');
            this.menu = this.wrap.querySelector('.fit-dropdown-menu');
            this.list = this.wrap.querySelector('.fit-dropdown-list');
            this.searchInput = this.wrap.querySelector('.fit-dropdown-search-input');
            this.searchBadge = this.wrap.querySelector('.fit-dropdown-search-badge');
        }

        _bindEvents() {
            this.trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.isOpen) this.close();
                else this.open();
            });

            this.trigger.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!this.isOpen) this.open();
                }
            });

            if (this.clearBtn) {
                this.clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.select(null);
                });
            }

            if (this.searchInput) {
                this.searchInput.addEventListener('click', (e) => e.stopPropagation());
                this.searchInput.addEventListener('input', (e) => {
                    const q = e.target.value;
                    this._filterList(q);
                    if (typeof this.options.onSearch === 'function') {
                        this.options.onSearch(q, (badgeText) => {
                            if (this.searchBadge) this.searchBadge.textContent = badgeText || '';
                        }, (newItems) => {
                            if (Array.isArray(newItems)) {
                                this.items = newItems.map(i => this._normalizeItem(i));
                                this._renderList(this.searchInput.value);
                            }
                        });
                    }
                });

                this.searchInput.addEventListener('keydown', (e) => {
                    const visibleItems = this.list.querySelectorAll('.fit-dropdown-item');
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        this.activeIndex = Math.min(this.activeIndex + 1, visibleItems.length - 1);
                        this._updateActiveItem(visibleItems);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        this.activeIndex = Math.max(this.activeIndex - 1, 0);
                        this._updateActiveItem(visibleItems);
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (this.activeIndex >= 0 && visibleItems[this.activeIndex]) {
                            visibleItems[this.activeIndex].click();
                        }
                    } else if (e.key === 'Escape') {
                        this.close();
                    }
                });
            }
        }

        _updateActiveItem(items) {
            items.forEach((el, idx) => {
                if (idx === this.activeIndex) {
                    el.classList.add('active');
                    el.scrollIntoView({ block: 'nearest' });
                } else {
                    el.classList.remove('active');
                }
            });
        }

        _renderTrigger() {
            if (this.selectedItem) {
                let iconHtml = '';
                if (this.selectedItem.avatar) {
                    iconHtml = `<div class="fit-dropdown-avatar" style="width:22px;height:22px;font-size:9.5px;border-width:1px;"><img src="${safeEscape(this.selectedItem.avatar)}" alt=""></div>`;
                } else if (this.selectedItem.initials) {
                    iconHtml = `<div class="fit-dropdown-avatar" style="width:22px;height:22px;font-size:9.5px;border-width:1px;">${safeEscape(this.selectedItem.initials)}</div>`;
                } else if (this.selectedItem.icon) {
                    iconHtml = `<span style="display:flex;align-items:center;opacity:0.8;">${this.selectedItem.icon}</span>`;
                } else if (this.selectedItem.dotClass) {
                    iconHtml = `<span class="status-indicator-dot ${safeEscape(this.selectedItem.dotClass)}"></span>`;
                }

                let subtitleHtml = this.selectedItem.subtitle ? `<span style="font-size:11.5px; color:var(--muted); margin-left:3px;">(${safeEscape(this.selectedItem.subtitle)})</span>` : '';
                let priceHtml = this.selectedItem.price ? `<span style="font-size:12px; font-weight:700; color:var(--lime); margin-left:auto; padding-right:4px;">${safeEscape(this.selectedItem.price)}</span>` : '';

                this.triggerContent.innerHTML = `
                    ${iconHtml}
                    <span style="font-weight:600; color:var(--ink); font-size:13.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        ${safeEscape(this.selectedItem.label)}
                    </span>
                    ${subtitleHtml}
                    ${priceHtml}
                `;

                if (this.clearBtn) this.clearBtn.style.display = 'block';
            } else {
                this.triggerContent.innerHTML = `
                    <span style="color:var(--muted); font-size:14px;">${safeEscape(this.options.placeholder)}</span>
                `;
                if (this.clearBtn) this.clearBtn.style.display = 'none';
            }
        }

        _renderList(query = '') {
            const q = query.trim().toLowerCase();
            const filtered = this.items.filter(item => {
                if (!q) return true;
                const matchLabel = item.label && item.label.toLowerCase().includes(q);
                const matchSub = item.subtitle && item.subtitle.toLowerCase().includes(q);
                const matchEmail = item.email && item.email.toLowerCase().includes(q);
                return matchLabel || matchSub || matchEmail;
            });

            if (filtered.length === 0) {
                this.list.innerHTML = `<div style="padding:12px;text-align:center;color:var(--muted);font-size:13px;">No options found</div>`;
                return;
            }

            this.list.innerHTML = filtered.map(item => {
                const isSelected = this.selectedItem && String(this.selectedItem.id) === String(item.id);
                
                let iconHtml = '';
                if (item.avatar) {
                    iconHtml = `<div class="fit-dropdown-avatar"><img src="${safeEscape(item.avatar)}" alt=""></div>`;
                } else if (item.initials) {
                    iconHtml = `<div class="fit-dropdown-avatar">${safeEscape(item.initials)}</div>`;
                } else if (item.icon) {
                    iconHtml = `<span style="display:flex;align-items:center;opacity:0.8;">${item.icon}</span>`;
                } else if (item.dotClass) {
                    iconHtml = `<span class="status-indicator-dot ${safeEscape(item.dotClass)}"></span>`;
                }

                let subHtml = item.subtitle ? `<span style="font-size:11.5px; color:var(--muted); margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${safeEscape(item.subtitle)}</span>` : '';
                let priceHtml = item.price ? `<span style="font-size:12px; font-weight:700; color:var(--lime);">${safeEscape(item.price)}</span>` : '';
                let checkHtml = isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--lime)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : '';

                return `
                    <div class="fit-dropdown-item ${isSelected ? 'selected' : ''}" data-id="${safeEscape(String(item.id))}" role="option" style="padding: 9px 12px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; width:100%; gap:10px;">
                            <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                                ${iconHtml}
                                <div style="display:flex; flex-direction:column; min-width:0; text-align:left;">
                                    <span style="font-weight:600; font-size:13px; color:var(--ink); line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        ${safeEscape(item.label)}
                                    </span>
                                    ${subHtml}
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                                ${priceHtml}
                                ${checkHtml}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            this.list.querySelectorAll('.fit-dropdown-item').forEach(el => {
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const id = el.getAttribute('data-id');
                    this.select(id, true);
                    this.close();
                });
            });
        }

        _filterList(query) {
            this.activeIndex = -1;
            this._renderList(query);
        }

        open() {
            FitDropdown.closeAll();
            this.isOpen = true;
            this.wrap.style.zIndex = '90';
            this.trigger.classList.add('open');
            this.trigger.setAttribute('aria-expanded', 'true');
            this.menu.style.display = 'block';

            if (this.searchInput) {
                this.searchInput.value = '';
                this._renderList('');
                setTimeout(() => this.searchInput.focus(), 50);
            } else {
                this._renderList('');
            }
        }

        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            this.wrap.style.zIndex = this.baseZIndex;
            this.trigger.classList.remove('open');
            this.trigger.setAttribute('aria-expanded', 'false');
            this.menu.style.display = 'none';
            this.activeIndex = -1;
        }

        select(id, triggerChange = true) {
            if (id === null || id === undefined || id === '') {
                this.selectedItem = null;
                this.input.value = '';
            } else {
                const found = this.items.find(item => String(item.id) === String(id));
                if (found) {
                    this.selectedItem = found;
                    this.input.value = found.id;
                } else {
                    this.selectedItem = null;
                    this.input.value = '';
                }
            }

            this.setError(false);
            this._renderTrigger();
            this._renderList(this.searchInput ? this.searchInput.value : '');

            if (triggerChange && typeof this.options.onChange === 'function') {
                this.options.onChange(this.selectedItem, this);
            }
        }

        getValue() {
            return this.input ? this.input.value : '';
        }

        getSelectedItem() {
            return this.selectedItem;
        }

        setItems(newItems) {
            this.items = (Array.isArray(newItems) ? newItems : []).map(i => this._normalizeItem(i));
            if (this.selectedItem) {
                const stillExists = this.items.find(item => String(item.id) === String(this.selectedItem.id));
                if (!stillExists) {
                    this.select(null, false);
                }
            }
            this._renderList(this.searchInput ? this.searchInput.value : '');
        }

        setError(hasError) {
            if (this.trigger) {
                if (hasError) this.trigger.classList.add('error');
                else this.trigger.classList.remove('error');
            }
        }

        destroy() {
            instances.delete(this);
            if (this.wrap) {
                this.wrap.innerHTML = '';
            }
        }
    }

    window.FitDropdown = FitDropdown;

})(window);
