const { createApp } = Vue;

const API = 'api/endpoints';
let keyCounter = 0;
const withKey = (obj) => ({ ...obj, _key: keyCounter++ });

createApp({
  data() {
    return {
      isAdmin: false,
      pending2fa: false,
      twofaCode: '',
      mustChangePassword: false,
      username: '',
      password: '',
      loggingIn: false,
      csrfToken: '',
      totpEnabled: false,
      twofaSetupData: null,
      twofaConfirmCode: '',
      disable2faPassword: '',
      banks: [],
      categories: [],
      goals: [],
      budgets: [],
      dist: { mode: 'percentage', items: [] },
      balanceTotal: 0,
      savingBanks: false,
      savingDist: false,
      savingCategories: false,
      savingGoals: false,
      savingBudgets: false,
      pwForm: { current: '', next: '' },
      pendingBackupFile: null,
      restoringBackup: false,
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
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('service-worker.js').catch(() => {});
    }
  },
  methods: {
    authHeaders() {
      return { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken };
    },

    async checkSession() {
      const res = await fetch(`${API}/session.php`);
      const data = await res.json();
      this.isAdmin = !!data.is_authed;
      this.username = data.username || '';
      this.csrfToken = data.csrf_token || '';
      this.mustChangePassword = !!data.must_change_password;
      this.totpEnabled = !!data.totp_enabled;
      if (this.isAdmin && !this.mustChangePassword) this.loadData();
    },

    async login() {
      this.loggingIn = true;
      try {
        const res = await fetch(`${API}/login.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username: this.username, password: this.password }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'No se pudo iniciar sesión.');

        if (data.requires_2fa) {
          this.pending2fa = true;
          this.password = '';
          return;
        }

        this.isAdmin = true;
        this.username = data.username;
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

    async verify2fa() {
      this.loggingIn = true;
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
        this.isAdmin = true;
        this.username = data.username;
        this.csrfToken = data.csrf_token;
        this.mustChangePassword = !!data.must_change_password;
        if (!this.mustChangePassword) this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.loggingIn = false;
      }
    },

    async startTwofaSetup() {
      try {
        const res = await fetch(`${API}/twofa_setup.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: '{}',
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo generar la clave.');
        this.twofaSetupData = data;
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async confirmTwofaSetup() {
      try {
        const res = await fetch(`${API}/twofa_enable.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ code: this.twofaConfirmCode }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'Código incorrecto.');
        this.totpEnabled = true;
        this.twofaSetupData = null;
        this.twofaConfirmCode = '';
        this.showToast('Verificación en dos pasos activada.', 'success');
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async disable2fa() {
      try {
        const res = await fetch(`${API}/twofa_disable.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ password: this.disable2faPassword }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo desactivar.');
        this.totpEnabled = false;
        this.disable2faPassword = '';
        this.showToast('Verificación en dos pasos desactivada.', 'success');
      } catch (err) {
        this.showToast(err.message, 'error');
      }
    },

    async logout() {
      await fetch(`${API}/session.php?action=logout`, { method: 'POST' });
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
        this.categories = (data.categories || []).map(withKey);
        this.goals = (data.goals || []).map(withKey);
        this.budgets = (data.budgets || []).map(withKey);
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
    addCategory() {
      this.categories.push(withKey({ id: null, name: '' }));
    },
    addGoal() {
      this.goals.push(withKey({ id: null, name: '', target_amount: '' }));
    },
    addBudget() {
      this.budgets.push(withKey({ id: null, category_id: this.categories[0]?.id ?? '', limit_amount: '' }));
    },

    async saveGoals() {
      this.savingGoals = true;
      try {
        const res = await fetch(`${API}/admin_save_goals.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ goals: this.goals }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudieron guardar los objetivos.');
        this.showToast('Objetivos guardados.', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingGoals = false;
      }
    },

    async saveBudgets() {
      this.savingBudgets = true;
      try {
        const res = await fetch(`${API}/admin_save_budgets.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ budgets: this.budgets }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudieron guardar los presupuestos.');
        this.showToast('Presupuestos guardados.', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingBudgets = false;
      }
    },

    async saveCategories() {
      this.savingCategories = true;
      try {
        const res = await fetch(`${API}/admin_save_categories.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ categories: this.categories }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudieron guardar las categorías.');
        this.showToast('Categorías guardadas.', 'success');
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.savingCategories = false;
      }
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
        this.showToast('Bancos guardados.', 'success');
        this.loadData();
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
        this.loadData();
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

    onBackupFileChosen(event) {
      this.pendingBackupFile = event.target.files[0] || null;
    },

    async restoreBackup() {
      if (!this.pendingBackupFile) return;

      const confirmed = confirm(
        '¿Seguro que quieres restaurar esta copia? Se BORRARÁN todos tus datos actuales ' +
        '(bancos, movimientos, categorías, objetivos, presupuestos y distribución) y se ' +
        'reemplazarán por los del archivo. Esta acción no se puede deshacer.'
      );
      if (!confirmed) return;

      this.restoringBackup = true;
      try {
        const text = await this.pendingBackupFile.text();
        let backup;
        try {
          backup = JSON.parse(text);
        } catch {
          throw new Error('El archivo no es un JSON válido.');
        }

        const res = await fetch(`${API}/import_backup.php`, {
          method: 'POST',
          headers: this.authHeaders(),
          body: JSON.stringify({ backup }),
        });
        const data = await res.json();
        if (await this.handleAuthErrors(res, data)) return;
        if (!res.ok) throw new Error(data.error || 'No se pudo restaurar la copia de seguridad.');

        const s = data.summary;
        this.showToast(
          `Restaurado: ${s.banks} bancos, ${s.categories} categorías, ${s.movements} movimientos, ${s.goals} objetivos, ${s.budgets} presupuestos.`,
          'success'
        );
        this.pendingBackupFile = null;
        if (this.$refs.backupFile) this.$refs.backupFile.value = '';
        this.loadData();
      } catch (err) {
        this.showToast(err.message, 'error');
      } finally {
        this.restoringBackup = false;
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
