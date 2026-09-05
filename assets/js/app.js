const { createApp, nextTick } = Vue;

const API = 'api/endpoints';

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}

createApp({
  data() {
    return {
      isAuthed: false,
      pending2fa: false,
      twofaCode: '',
      mustChangePassword: false,
      username: '',
      authMode: 'login',
      authForm: { username: '', password: '' },
      authBusy: false,
      csrfToken: '',
      theme: document.documentElement.getAttribute('data-theme') || 'dark',
      notificationsEnabled: localStorage.getItem('arca-notifications-enabled') === '1',
      banks: [],
      categories: [],
      selectedBankId: '',
      balance: { total: 0, history: [] },
      distribution: { mode: 'percentage', items: [], assigned: 0, remaining: 0, total: 0 },
      goals: [],
      goalContribution: {},
      budgets: [],
      stats: { month: '', income: 0, expense: 0, net: 0, savings_rate: 0, previous_month_net: 0, change_percent: null },
      balanceSeries: [],
      newEntry: { amount: '', note: '', date: todayISO(), category_id: '' },
      editingId: null,
      savingEntry: false,
      toast: { message: '', type: 'success' },
      todayStr: todayISO(),
    };
  },
  mounted() {
    this.chartInstance = null;
    this.notifiedOverBudgetIds = new Set();
    this.notifiedCompleteGoalIds = new Set();
    this.checkSession();
    this.registerServiceWorker();
  },
  methods: {
    toggleTheme() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', this.theme);
      localStorage.setItem('arca-theme', this.theme);
      this.renderChart();
    },

    async toggleNotifications() {
      if (this.notificationsEnabled) {
        this.notificationsEnabled = false;
        localStorage.setItem('arca-notifications-enabled', '0');
        return;
      }
      if (!('Notification' in window)) {
        this.showToast('Tu navegador no admite notificaciones.', 'error');
        return;
      }
      const permission = await Notification.requestPermission();
      if (permission === 'granted') {
        this.notificationsEnabled = true;
        localStorage.setItem('arca-notifications-enabled', '1');
        new Notification('Arca', { body: 'Te avisaré si superas un presupuesto o completas un objetivo.' });
      } else {
        this.showToast('Debes permitir las notificaciones en tu navegador para activarlas.', 'error');
      }
    },

    // Compara el estado de presupuestos/objetivos con el de la última carga
    // (en memoria, dura mientras la pestaña está abierta) para avisar solo
    // en el momento en que se cruza el umbral, no en cada recarga.
    checkAndFireNotifications() {
      if (!this.notificationsEnabled || !('Notification' in window) || Notification.permission !== 'granted') {
        return;
      }

      for (const b of this.budgets) {
        if (b.over_budget && !this.notifiedOverBudgetIds.has(b.id)) {
          this.notifiedOverBudgetIds.add(b.id);
          new Notification('Presupuesto superado', {
            body: `${b.category_name}: ${this.formatMoney(b.spent)} de ${this.formatMoney(b.limit_amount)} este mes.`,
          });
        }
        if (!b.over_budget) {
          this.notifiedOverBudgetIds.delete(b.id); // si vuelve a bajar del límite, se puede volver a avisar en el futuro
        }
      }

      for (const g of this.goals) {
        if (g.percent >= 100 && !this.notifiedCompleteGoalIds.has(g.id)) {
          this.notifiedCompleteGoalIds.add(g.id);
          new Notification('¡Objetivo completado! 🎉', { body: `${g.name}: alcanzaste tu meta de ${this.formatMoney(g.target_amount)}.` });
        }
      }
    },

    registerServiceWorker() {
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js').catch(() => {
          // Si falla (ej. servidor sin HTTPS en local), la app sigue funcionando igual sin PWA.
        });
      }
    },

    async checkSession() {
      try {
        const res = await fetch(`${API}/session.php`);
        const data = await res.json();
        this.isAuthed = !!data.is_authed;
        this.username = data.username || '';
        this.csrfToken = data.csrf_token || '';
        this.mustChangePassword = !!data.must_change_password;
        if (this.isAuthed && !this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast('No se pudo comprobar la sesión.', 'error');
      }
    },

    async login() {
      this.authBusy = true;
      try {
        const res = await fetch(`${API}/login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.authForm),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'No se pudo iniciar sesión.');

        if (data.requires_2fa) {
          this.pending2fa = true;
          this.authForm = { username: '', password: '' };
          return;
        }

        this.isAuthed = true;
        this.username = data.username;
        this.csrfToken = data.csrf_token;
        this.mustChangePassword = !!data.must_change_password;
        this.authForm = { username: '', password: '' };
        if (!this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.authBusy = false;
      }
    },

    async verify2fa() {
      this.authBusy = true;
      try {
        const res = await fetch(`${API}/twofa_login_verify.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ code: this.twofaCode }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Código incorrecto.');

        this.pending2fa = false;
        this.twofaCode = '';
        this.isAuthed = true;
        this.username = data.username;
        this.csrfToken = data.csrf_token;
        this.mustChangePassword = !!data.must_change_password;
        if (!this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.authBusy = false;
      }
    },

    async register() {
      this.authBusy = true;
      try {
        const res = await fetch(`${API}/register.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.authForm),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'No se pudo crear la cuenta.');
        this.isAuthed = true;
        this.username = data.username;
        this.csrfToken = data.csrf_token;
        this.mustChangePassword = false;
        this.authForm = { username: '', password: '' };
        this.showToast('Cuenta creada. ¡Bienvenido/a!', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.authBusy = false;
      }
    },

    async logout() {
      await fetch(`${API}/session.php?action=logout`, { method: 'POST' });
      this.isAuthed = false;
      this.pending2fa = false;
      this.mustChangePassword = false;
    },

    authHeaders() {
      return { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken };
    },

    async handleAuthErrors(res, data) {
      if (res.status === 401) {
        this.isAuthed = false;
        this.showToast('Tu sesión ha expirado. Vuelve a iniciar sesión.', 'error');
        return true;
      }
      if (res.status === 403 && data && data.must_change_password) {
        this.mustChangePassword = true;
        return true;
      }
      return false;
    },

    async loadData() {
      try {
        const res = await fetch(`${API}/get_data.php`);
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'Error al cargar los datos.');
        this.banks = data.banks;
        this.categories = data.categories || [];
        this.balance = data.balance;
        this.distribution = data.distribution;
        this.goals = data.goals || [];
        this.budgets = data.budgets || [];
        this.stats = data.stats || this.stats;
        this.balanceSeries = data.balance_series || [];
        await nextTick();
        this.renderChart();
        this.checkAndFireNotifications();
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    goToBank() {
      const bank = this.banks.find(b => b.id === this.selectedBankId);
      if (!bank) return;
      window.location.href = bank.url;
    },

    categoryName(categoryId) {
      if (!categoryId) return '';
      const cat = this.categories.find(c => c.id === categoryId);
      return cat ? cat.name : '';
    },

    startEdit(entry) {
      this.editingId = entry.id;
      this.newEntry = {
        amount: entry.amount,
        note: entry.note === 'Movimiento manual' ? '' : entry.note,
        date: entry.date,
        category_id: entry.category_id || '',
      };
      document.querySelector('.balance-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    },

    cancelEdit() {
      this.editingId = null;
      this.newEntry = { amount: '', note: '', date: this.todayStr, category_id: '' };
    },

    submitBalance() {
      return this.editingId ? this.updateBalance() : this.addBalance();
    },

    buildPayload() {
      const amount = parseFloat(this.newEntry.amount);
      return {
        amount,
        note: this.newEntry.note,
        date: this.newEntry.date || this.todayStr,
        category_id: this.newEntry.category_id || null,
      };
    },

    async addBalance() {
      const amount = parseFloat(this.newEntry.amount);
      if (!Number.isFinite(amount) || amount === 0) {
        this.showToast('Ingresa un monto válido y distinto de cero.', 'error');
        return;
      }
      this.savingEntry = true;
      try {
        const res = await fetch(`${API}/add_balance.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify(this.buildPayload()),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo guardar el movimiento.');
        this.cancelEdit();
        this.showToast('Movimiento registrado.', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingEntry = false;
      }
    },

    async updateBalance() {
      const amount = parseFloat(this.newEntry.amount);
      if (!Number.isFinite(amount) || amount === 0) {
        this.showToast('Ingresa un monto válido y distinto de cero.', 'error');
        return;
      }
      this.savingEntry = true;
      try {
        const res = await fetch(`${API}/update_balance.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ id: this.editingId, ...this.buildPayload() }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo actualizar el movimiento.');
        this.cancelEdit();
        this.showToast('Movimiento actualizado.', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingEntry = false;
      }
    },

    async removeEntry(id) {
      if (!confirm('¿Eliminar este movimiento?')) return;
      try {
        const res = await fetch(`${API}/delete_balance.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ id }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo eliminar.');
        if (this.editingId === id) this.cancelEdit();
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async contributeToGoal(goalId) {
      const raw = this.goalContribution[goalId];
      const amount = parseFloat(raw);
      if (!Number.isFinite(amount) || amount === 0) {
        this.showToast('Ingresa un monto válido para el objetivo.', 'error');
        return;
      }
      try {
        const res = await fetch(`${API}/add_goal_contribution.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ id: goalId, amount }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo actualizar el objetivo.');
        this.goals = data.goals;
        this.goalContribution[goalId] = '';
        this.showToast('Objetivo actualizado.', 'success');
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    exportCSV() {
      const header = ['Fecha', 'Concepto', 'Categoría', 'Monto'];
      const rows = [...this.balance.history]
        .sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id)
        .map(e => [e.date, e.note, this.categoryName(e.category_id) || '', e.amount]);

      const escapeCsv = (value) => {
        const str = String(value ?? '');
        return /[",\n]/.test(str) ? '"' + str.replace(/"/g, '""') + '"' : str;
      };

      const csv = [header, ...rows].map(row => row.map(escapeCsv).join(',')).join('\r\n');
      const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `arca-movimientos-${this.todayStr}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },

    renderChart() {
      const canvas = this.$refs.balanceCanvas;
      if (!canvas || this.balanceSeries.length < 2 || typeof Chart === 'undefined') return;

      const styles = getComputedStyle(document.documentElement);
      const accent = styles.getPropertyValue('--accent').trim() || '#c9a24b';
      const teal = styles.getPropertyValue('--teal').trim() || '#4a9d9d';
      const textDim = styles.getPropertyValue('--text-dim').trim() || '#93a8a5';
      const lineSoft = styles.getPropertyValue('--line-soft').trim() || 'rgba(237,231,218,0.07)';

      const labels = this.balanceSeries.map(p => this.formatDate(p.date));
      const values = this.balanceSeries.map(p => p.total);

      if (this.chartInstance) {
        this.chartInstance.destroy();
      }

      this.chartInstance = new Chart(canvas, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            data: values,
            borderColor: accent,
            backgroundColor: teal + '33',
            fill: true,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
          }],
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            x: { grid: { color: lineSoft }, ticks: { color: textDim, maxTicksLimit: 6 } },
            y: { grid: { color: lineSoft }, ticks: { color: textDim } },
          },
        },
      });
    },

    barWidth(item) {
      if (this.distribution.mode === 'percentage') return Math.min(item.value, 100);
      if (!this.distribution.total) return 0;
      return Math.min((item.amount / this.distribution.total) * 100, 100);
    },

    formatMoney(value) {
      return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(value || 0);
    },
    formatDate(value) {
      return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value + 'T00:00:00'));
    },
    formatMonthLabel(monthStr) {
      if (!monthStr) return '';
      const d = new Date(monthStr + '-01T00:00:00');
      const label = new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' }).format(d);
      return label.charAt(0).toUpperCase() + label.slice(1);
    },

    showToast(message, type = 'success') {
      this.toast = { message, type };
      setTimeout(() => { this.toast.message = ''; }, 3200);
    },
  },
}).mount('#app');
