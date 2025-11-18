(function (window, document) {
    'use strict';

    // ============================================================
    // FRONTEND MODULE BLUEPRINT — UNIVERSAL REUSABLE TEMPLATE
    // ============================================================

    const ModuleBlueprint = {
        // ------------------------------
        // SELECTOR MAP (ADAPT TO MODULE)
        // ------------------------------
        selectors: {
            block: '.module-block',
            tabButtons: '.module-tabs [data-tab-slug]',
            tabDropdown: '[data-module-role="tab-dropdown"]',
            navigation: '.module-navigation',
            navButtons: '.module-navigation [data-direction]',
            paginationInfo: '.module-navigation .pagination-info',
            content: '.module-content',
            card: '.module-card',
            lazyImage: '[data-module-src]',
            lazyBackground: '[data-module-bg]'
        },

        // ------------------------------
        // INTERNAL STATE MANAGEMENT
        // ------------------------------
        state: new WeakMap(),
        resizeTimeout: null,
        observer: null,
        _resizeListenerAttached: false,

        // ------------------------------
        // INITIALIZATION LIFECYCLE
        // ------------------------------
        init() {
            this.getBlocks().forEach((block) => this.primeBlock(block));
            this.setupResizeListener();
            this.setupMutationObserver();
        },

        getBlocks() {
            return Array.from(document.querySelectorAll(this.selectors.block));
        },

        primeBlock(container) {
            if (container.dataset.moduleInitialized === 'true') return;

            // Parse configuration and set default state
            const config = this.parseConfig(container.getAttribute('data-module-config'));
            const activeSlug = this.getDefaultTabSlug(container);
            const state = { config, currentPage: 1, totalPages: 1, activeSlug };
            this.state.set(container, state);

            // Core bindings — adapt as needed per project
            this.bindUI(container);
            this.applyStyles(container, config);
            this.adjustResponsiveLayout(container);

            // Optional auto-load pattern
            if (container.getAttribute('data-module-auto-load') === 'true' && activeSlug) {
                this.fetchAndRender(container);
            }

            container.dataset.moduleInitialized = 'true';
        },

        // ------------------------------
        // CONFIGURATION PARSING
        // ------------------------------
        parseConfig(raw) {
            if (!raw) return {};
            try {
                return JSON.parse(raw);
            } catch (e) {
                console.warn('ModuleBlueprint: invalid config JSON', e);
                return {};
            }
        },

        getDefaultTabSlug(container) {
            // Return first available tab slug from button or dropdown
            const button = container.querySelector(this.selectors.tabButtons);
            if (button) return button.getAttribute('data-tab-slug') || '';
            const dropdown = container.querySelector(this.selectors.tabDropdown);
            const option = dropdown?.options[dropdown.selectedIndex] || dropdown?.options[0];
            return option?.getAttribute('data-tab-slug') || option?.value || '';
        },

        // ------------------------------
        // UI EVENT BINDING
        // ------------------------------
        bindUI(container) {
            // Uncomment or replace as needed
            // this.bindTabButtons(container);
            // this.bindDropdown(container);
            // this.bindNavigation(container);
            // this.initializeLazyEffects(container);
        },

        // bindTabButtons(container) {
        //     // Example pattern
        //     const buttons = container.querySelectorAll(this.selectors.tabButtons);
        //     buttons.forEach((button) => {
        //         button.addEventListener('click', (e) => {
        //             e.preventDefault();
        //             this.handleTabChange(container, button.getAttribute('data-tab-slug'));
        //         });
        //     });
        // },

        // ------------------------------
        // INTERACTION HANDLERS
        // ------------------------------
        // handleTabChange(container, slug) { ... }
        // handleNavigation(container, direction) { ... }

        // ------------------------------
        // DATA FLOW + RENDER PIPELINE
        // ------------------------------
        fetchAndRender(container) {
            const state = this.state.get(container);
            const target = container.querySelector(this.selectors.content);
            if (!state || !target) return Promise.resolve();

            this.setLoading(container, true);
            target.innerHTML = `<div class="module-loading">Loading...</div>`;

            // Replace requestContent() with your own data-source logic
            return this.requestContent(container, state.activeSlug, state.currentPage)
                .then((data) => this.renderContent(container, data))
                .catch(() => this.renderError(target, 'Load failed.'))
                .finally(() => this.setLoading(container, false));
        },

        requestContent(container, tabSlug, page) {
            // Stub for network operations (AJAX, Fetch API, etc.)
            // Example payload shown for context:
            /*
            const config = this.state.get(container)?.config || {};
            const payload = { action: 'module_load_content', tab_slug: tabSlug, page };
            const ajaxUrl = config.ajaxUrl || '/api/endpoint';
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(payload)
            }).then((res) => res.json());
            */
            return Promise.resolve({ success: true, data: { html: '<p>Demo content</p>' } });
        },

        renderContent(container, response) {
            const target = container.querySelector(this.selectors.content);
            if (!target) return;
            if (!response?.success) return this.renderError(target, response?.data?.message || 'No content.');
            target.innerHTML = response.data.html || '';
            // this.initializeLazyEffects(container);
            // this.updateNavigation(container, response.data.pagination || {});
        },

        renderError(target, message) {
            target.innerHTML = `<div class="module-error">${message}</div>`;
        },

        // ------------------------------
        // STATE MANAGEMENT
        // ------------------------------
        setLoading(container, flag) {
            container.dataset.moduleLoading = flag ? '1' : '0';
        },

        isLoading(container) {
            return container.dataset.moduleLoading === '1';
        },

        // ------------------------------
        // VISUAL CONFIGURATION
        // ------------------------------
        applyStyles(container, config) {
            // Example: apply dynamic CSS variables
            const vars = {
                '--module-primary': config.primaryColor || '#0055ff',
                '--module-radius': (config.borderRadius ?? 16) + 'px'
            };
            Object.entries(vars).forEach(([k, v]) => container.style.setProperty(k, v));
        },

        // initializeLazyEffects(container) { ... }
        // lazyLoadImage(image) { ... }
        // lazyLoadBackground(el) { ... }

        // ------------------------------
        // RESPONSIVE + OBSERVERS
        // ------------------------------
        adjustResponsiveLayout(container) {
            // Handle breakpoint-based layout toggles
            const tabs = container.querySelector('.module-tabs');
            if (window.innerWidth <= 768) tabs?.classList.add('is-mobile');
            else tabs?.classList.remove('is-mobile');
        },

        setupResizeListener() {
            if (this._resizeListenerAttached) return;
            window.addEventListener('resize', () => this.handleResize());
            this._resizeListenerAttached = true;
        },

        handleResize() {
            clearTimeout(this.resizeTimeout);
            this.resizeTimeout = setTimeout(() => {
                this.getBlocks().forEach((block) => this.adjustResponsiveLayout(block));
            }, 200);
        },

        setupMutationObserver() {
            if (this.observer) return;
            this.observer = new MutationObserver((mutations) => {
                mutations.forEach((m) => {
                    m.addedNodes.forEach((n) => {
                        if (n.nodeType === 1 && (n.matches?.(this.selectors.block) || n.querySelector?.(this.selectors.block))) {
                            this.primeBlock(n);
                        }
                    });
                });
            });
            this.observer.observe(document.body, { childList: true, subtree: true });
        },

        // ------------------------------
        // UTILITY METHODS
        // ------------------------------
        scrollIntoView(container) {
            const offset = Number(container.getAttribute('data-module-scroll-offset') || 100);
            const top = container.getBoundingClientRect().top + window.scrollY - offset;
            window.scrollTo({ top, behavior: 'smooth' });
        },

        reinitialize() {
            this.getBlocks().forEach((container) => {
                container.dataset.moduleInitialized = 'false';
                this.primeBlock(container);
            });
        }
    };

    // ------------------------------
    // BOOTSTRAPPING
    // ------------------------------
    document.addEventListener('DOMContentLoaded', () => ModuleBlueprint.init());
    if (window.wp?.domReady) window.wp.domReady(() => ModuleBlueprint.init());
    window.moduleBlueprint = ModuleBlueprint;

})(window, document);
