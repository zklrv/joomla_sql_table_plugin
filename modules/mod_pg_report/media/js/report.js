(() => {
  const modules = document.querySelectorAll('.pg-report');

  modules.forEach((container) => {
    const endpoint = container.dataset.endpoint;
    const token = container.dataset.token;
    const moduleId = container.dataset.moduleId;

    const searchInput = container.querySelector('.pg-report__search');
    const perPageSelect = container.querySelector('.pg-report__per-page');
    const content = container.querySelector('.pg-report__content');
    const messages = container.querySelector('.pg-report__messages');

    const state = {
      sort: container.dataset.defaultSort || '',
      dir: container.dataset.defaultDir || 'asc',
      page: 1,
      perPage: Number(container.dataset.defaultPerPage || 25),
      search: '',
    };

    let searchTimer = null;
    const hiddenRowClass = 'pg-report__row--hidden';

    const setMessage = (message, isError = false) => {
      messages.textContent = message || '';
      messages.classList.toggle('pg-report__messages--error', !!isError);
    };

    const parseResponse = (payload) => {
      if (Array.isArray(payload?.data) && payload.data.length === 1 && typeof payload.data[0] === 'object') {
        return payload.data[0];
      }

      return payload?.data || payload;
    };

    const setGroupCollapsed = (toggleTarget, collapsed) => {
      const group = toggleTarget.dataset.group;
      const rows = content.querySelectorAll(`.pg-report__data-row[data-group="${group}"]`);
      const icon = toggleTarget.querySelector('.pg-report__toggle-icon');
      const text = toggleTarget.querySelector('.pg-report__toggle-text');
      const actionLabel = collapsed ? toggleTarget.dataset.labelExpand : toggleTarget.dataset.labelCollapse;

      rows.forEach((row) => {
        row.classList.toggle(hiddenRowClass, collapsed);
      });

      toggleTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

      if (icon) {
        icon.textContent = collapsed ? '+' : '−';
      }

      if (text) {
        text.textContent = actionLabel || '';
      }
    };

    const applyInitialCollapsedState = () => {
      if (container.dataset.collapsedByDefault !== '1') {
        return;
      }

      content.querySelectorAll('.pg-report__toggle').forEach((toggleTarget) => {
        setGroupCollapsed(toggleTarget, true);
      });
    };

    const load = async () => {
      setMessage('');
      content.classList.add('pg-report__content--loading');

      const body = new URLSearchParams();
      body.set('module_id', moduleId);
      body.set('search', state.search);
      body.set('sort', state.sort);
      body.set('dir', state.dir);
      body.set('page', String(state.page));
      body.set('per_page', String(state.perPage));
      body.set(token, '1');

      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: body.toString(),
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const data = parseResponse(payload);

        if (!data || data.success === false) {
          throw new Error(data?.error || 'Unknown response error');
        }

        content.innerHTML = data.html || '';
        applyInitialCollapsedState();

        if (Array.isArray(data.warnings) && data.warnings.length) {
          setMessage(data.warnings.join(' | '), false);
        }
      } catch (error) {
        content.innerHTML = '';
        setMessage(error.message || 'Request failed', true);
      } finally {
        content.classList.remove('pg-report__content--loading');
      }
    };

    searchInput?.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(() => {
        state.search = searchInput.value || '';
        state.page = 1;
        load();
      }, 250);
    });

    perPageSelect?.addEventListener('change', () => {
      state.perPage = Number(perPageSelect.value || 25);
      state.page = 1;
      load();
    });

    content.addEventListener('click', (event) => {
      const sortTarget = event.target.closest('th[data-sort]');

      if (sortTarget) {
        state.sort = sortTarget.dataset.sort || state.sort;
        state.dir = sortTarget.dataset.dir || 'asc';
        state.page = 1;
        load();
        return;
      }

      const pageTarget = event.target.closest('button[data-page]');

      if (pageTarget) {
        state.page = Number(pageTarget.dataset.page || 1);
        load();
        return;
      }

      const toggleTarget = event.target.closest('.pg-report__toggle');

      if (toggleTarget) {
        const isCurrentlyExpanded = toggleTarget.getAttribute('aria-expanded') !== 'false';
        setGroupCollapsed(toggleTarget, isCurrentlyExpanded);
      }
    });

    load();
  });
})();
