const { createApp } = Vue;

const API = 'api/endpoints';
let keyCounter = 0;
const withKey = (obj) => ({ ...obj, _key: keyCounter++ });

createApp({
  data() {
    return {
      isAdmin: false,
      mustChangePassword: false,
      password: '',
      loggingIn: false,
      csrfToken: '',
      banks: [],
      dist: { mode: 'percentage', items: [] },
      balanceTotal: 0,
      savingBanks: false,
      savingDist: false,
      pwForm: { current: '', next: '' },
      toast: { message: '', type: 'success' },
    };
  },
  computed: {
    percentSum() {
      return this.dist.items.reduce((sum, i) => sum + (parseFloat(i.value) || 0), 0);
    },
  },
  mounted() {
    this.checkSession();
  },
  methods: {
    authHeaders() {
      return { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken };
    },

    async checkSession() {
      const res = await fetch(`${API}/admin_session.php`);
      const data = await res.json();
      this.isAdmin = !!data.is_admin;
      this.csrfToken = data.csrf_token || '';
      this.mustChangePassword = !!data.must_change_password;
      if (this.isAdmin && !this.mustChangePassword) this.loadData();
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
        this.isAdmin = true;
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
      this.isAdmin = false;
      this.mustChangePassword = false;
    },

    async handleAuthErrors(res, data) {
      if (res.status === 401) {
        this.isAdmin = false;
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
        const res = await fetch(`${API}/admin_get_data.php`);
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'Error al cargar los datos.');
        this.banks = data.banks.map(withKey);
        this.dist = { mode: data.distribution.mode, items: data.distribution.items.map(withKey) };
        this.balanceTotal = data.balance_total;
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    addBank() {
      this.banks.push(withKey({ id: null, name: '', url: '', active: true }));
    },
    addDistItem() {
      this.dist.items.push(withKey({ id: null, concept: '', value: 0 }));
    },

    async saveBanks() {
      this.savingBanks = true;
      try {
        const res = await fetch(`${API}/admin_save_banks.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ banks: this.banks }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudieron guardar los bancos.');
        this.banks = data.banks.map(withKey);
        this.showToast('Bancos guardados.', 'success');
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingBanks = false;
      }
    },

    async saveDistribution() {
      this.savingDist = true;
      try {
        const res = await fetch(`${API}/admin_save_distribution.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ mode: this.dist.mode, items: this.dist.items }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo guardar la distribución.');
        this.showToast('Distribución guardada.', 'success');
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingDist = false;
      }
    },

    async changePassword() {
      try {
        const res = await fetch(`${API}/admin_change_password.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ current_password: this.pwForm.current, new_password: this.pwForm.next }),
        });
        const data = await res.json();
        if (res.status === 401) { this.isAdmin = false; return; }
        if (!res.ok) throw new Error(data.error || 'No se pudo cambiar la contraseña.');
        this.pwForm = { current: '', next: '' };
        const wasForced = this.mustChangePassword;
        this.mustChangePassword = false;
        this.showToast('Contraseña actualizada.', 'success');
        if (wasForced) this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    formatMoney(value) {
      return new Intl.NumberFormat('es-ES', { style: 'currency', currency: 'EUR' }).format(value || 0);
    },
    showToast(message, type = 'success') {
      this.toast = { message, type };
      setTimeout(() => { this.toast.message = ''; }, 3200);
    },
  },
}).mount('#app');
