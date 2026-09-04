const { createApp } = Vue;

const API = 'api/endpoints';

createApp({
  data() {
    return {
      isAuthed: false,
      mustChangePassword: false,
      password: '',
      loggingIn: false,
      csrfToken: '',
      banks: [],
      selectedBankId: '',
      balance: { total: 0, history: [] },
      distribution: { mode: 'percentage', items: [], assigned: 0, remaining: 0, total: 0 },
      newEntry: { amount: '', note: '' },
      savingEntry: false,
      toast: { message: '', type: 'success' },
    };
  },
  mounted() {
    this.checkSession();
  },
  methods: {
    async checkSession() {
      try {
        const res = await fetch(`${API}/admin_session.php`);
        const data = await res.json();
        this.isAuthed = !!data.is_admin;
        this.csrfToken = data.csrf_token || '';
        this.mustChangePassword = !!data.must_change_password;
        if (this.isAuthed && !this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast('No se pudo comprobar la sesión.', 'error');
      }
    },

    async login() {
      this.loggingIn = true;
      try {
        const res = await fetch(`${API}/admin_login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ password: this.password }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'No se pudo iniciar sesión.');
        this.isAuthed = true;
        this.csrfToken = data.csrf_token;
        this.mustChangePassword = !!data.must_change_password;
        this.password = '';
        if (!this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.loggingIn = false;
      }
    },

    async logout() {
      await fetch(`${API}/admin_session.php?action=logout`, { method: 'POST' });
      this.isAuthed = false;
      this.mustChangePassword = false;
    },

    authHeaders() {
      return { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken };
    },

    // Centraliza el manejo de 401 (sesión expirada) y 403 con must_change_password,
    // para que todas las llamadas reaccionen igual.
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
        this.balance = data.balance;
        this.distribution = data.distribution;
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    goToBank() {
      const bank = this.banks.find(b => b.id === this.selectedBankId);
      if (!bank) return;
      window.location.href = bank.url;
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
          body: JSON.stringify({ amount, note: this.newEntry.note }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo guardar el movimiento.');
        this.balance = data.balance;
        this.distribution = data.distribution;
        this.newEntry = { amount: '', note: '' };
        this.showToast('Movimiento registrado.', 'success');
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
        this.balance = data.balance;
        this.distribution = data.distribution;
      } catch (err) {
        this.showToast(err.message, 'error');
      }
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
      return new Intl.DateTimeFormat('es-ES', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
    },

    showToast(message, type = 'success') {
      this.toast = { message, type };
      setTimeout(() => { this.toast.message = ''; }, 3200);
    },
  },
}).mount('#app');
