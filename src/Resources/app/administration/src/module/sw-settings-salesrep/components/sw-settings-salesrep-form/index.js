// sw-settings-salesrep-form.index.js
import template from './sw-settings-salesrep-form.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-settings-salesrep-form', {
  template,
  inject: ['repositoryFactory', 'systemConfigApiService'],
  mixins: [Mixin.getByName('notification')],

  data() {
    return {
      isLoading: false,
      global: {
        excludedEmailsCsv: '',
      },
      selectedUserId: null,
      currentConfigId: null,
      salesrepRoleId: null,
      cfFilterPath: null,
      cfFilterValue: null,
      form: {
        commissionPercentage: null,
        discountLimit: null,
      },
    };
  },

  computed: {
    userCriteria() {
      const c = new Criteria(1, 25);
      if (this.cfFilterPath && this.cfFilterValue !== null) {
        c.addFilter(Criteria.equals(this.cfFilterPath, this.cfFilterValue));
      }
      if (this.salesrepRoleId) {
        c.addFilter(Criteria.equalsAny('aclRoles.id', [this.salesrepRoleId]));
      }
      c.addAssociation('aclRoles');
      c.addSorting(Criteria.sort('firstName', 'ASC'));
      c.addSorting(Criteria.sort('lastName', 'ASC'));
      return c;
    },

    userCriteriaKey() {
      return JSON.stringify({
        cf: this.cfFilterPath || 'none',
        val: this.cfFilterValue,
        role: this.salesrepRoleId || 'none',
      });
    },
  },

  watch: {
    async selectedUserId(newId) {
      if (!newId) {
        this.resetForm();
        this.currentConfigId = null;
        return;
      }
      await this.loadConfig(newId);
    },
  },

  async created() {
    this.configRepository = this.repositoryFactory.create('salesrep_config');

    await this.loadGlobal();

    await this.resolveSalesrepRoleId();
    await this.detectWorkingCustomFieldFilter();
    await this.probeAgents();

    if (this.selectedUserId) {
      await this.loadConfig(this.selectedUserId);
    }
  },

  methods: {
    parseCsvToArray(csv) {
      if (!csv) return [];
      return String(csv)
        .split(/[,;\s]+/)
        .map(s => s.trim())
        .filter(Boolean);
    },

    arrToCsv(arr) {
      return (arr || []).join(', ');
    },

    async loadGlobal() {
      this.isLoading = true;
      try {
        const values = await this.systemConfigApiService.getValues('Salesrep');
        const raw = values['Salesrep.config.excludedEmails'];

        const emails = Array.isArray(raw)
          ? raw
          : (typeof raw === 'string' ? this.parseCsvToArray(raw) : []);
        this.global.excludedEmailsCsv = this.arrToCsv(emails);
      } catch (e) {
        this.createNotificationError({
          title: 'Sales Agent',
          message: 'Failed to load global settings.',
        });
      } finally {
        this.isLoading = false;
      }
    },

    async saveGlobal() {
      const emails = this.parseCsvToArray(this.global.excludedEmailsCsv);

      const bad = emails.filter(e => !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e));
      if (bad.length) {
        this.createNotificationError({
          title: 'Sales Agent',
          message: `Invalid email(s): ${bad.join(', ')}`,
        });
        return false;
      }

      this.isLoading = true;
      try {
        await this.systemConfigApiService.saveValues({
          'Salesrep.config.excludedEmails': emails,
        });
        this.createNotificationSuccess({
          title: 'Sales Agent',
          message: 'Global exclusions saved.',
        });
        return true;
      } catch (e) {
        const detail =
          e?.response?.data?.errors?.[0]?.detail ||
          e?.response?.data?.errors?.[0]?.title ||
          e?.message || 'Unknown error';
        this.createNotificationError({
          title: 'Sales Agent',
          message: `Failed to save global settings: ${detail}`,
        });
        return false;
      } finally {
        this.isLoading = false;
      }
    },

    async resolveSalesrepRoleId() {
      try {
        const roleRepo = this.repositoryFactory.create('acl_role');
        const crit = new Criteria(1, 1);
        crit.addFilter(Criteria.equals('name', 'Sales rep'));
        const res = await roleRepo.search(crit, Shopware.Context.api);
        this.salesrepRoleId = res.first()?.id || null;
      } catch {
        this.salesrepRoleId = null;
      }
    },

    async detectWorkingCustomFieldFilter() {
      const candidates = [
        { path: 'customFields.is_salesrep', value: true },
        { path: 'customFields.is_salesrep', value: 1 },
        { path: 'customFields.salesrep.is_salesrep', value: true },
        { path: 'customFields.salesrep.is_salesrep', value: 1 },
        { path: 'customFields.salesrep_is_salesrep', value: true },
        { path: 'customFields.salesrep_is_salesrep', value: 1 },
      ];

      const userRepo = this.repositoryFactory.create('user');

      for (const cand of candidates) {
        try {
          const crit = new Criteria(1, 1);
          crit.addFilter(Criteria.equals(cand.path, cand.value));
          if (this.salesrepRoleId) {
            crit.addFilter(Criteria.equalsAny('aclRoles.id', [this.salesrepRoleId]));
          }
          const res = await userRepo.search(crit, Shopware.Context.api);
          if (res.length > 0) {
            this.cfFilterPath = cand.path;
            this.cfFilterValue = cand.value;
            return;
          }
        } catch(e) {
          console.warn(e)
        }
      }

      this.cfFilterPath = null;
      this.cfFilterValue = null;
    },

    async probeAgents() {
      try {
        const userRepo = this.repositoryFactory.create('user');
        const crit = new Criteria(1, 50);

        if (this.cfFilterPath && this.cfFilterValue !== null) {
          crit.addFilter(Criteria.equals(this.cfFilterPath, this.cfFilterValue));
        }
        if (this.salesrepRoleId) {
          crit.addFilter(Criteria.equalsAny('aclRoles.id', [this.salesrepRoleId]));
        }

        crit.addAssociation('aclRoles');
        await userRepo.search(crit, Shopware.Context.api);
      } catch (e) {
        console.error('[SalesAgent] probe failed', e);
      }
    },

    resetForm() {
      this.currentConfigId = null;
      this.form = {
        commissionPercentage: null,
        discountLimit: null,
      };
    },

    async loadConfig(userId) {
      this.isLoading = true;
      try {
        const criteria = new Criteria(1, 1);
        criteria.addFilter(Criteria.equals('userId', userId));
        const result = await this.configRepository.search(criteria, Shopware.Context.api);
        const entity = result.first();

        if (entity) {
          this.currentConfigId = entity.id;
          this.form.commissionPercentage = entity.commissionPercentage ?? null;
          this.form.discountLimit = entity.discountLimit ?? null;
        } else {
          this.resetForm();
        }
      } catch {
        this.createNotificationError({ title: 'Sales Agent', message: 'Failed to load user config.' });
      } finally {
        this.isLoading = false;
      }
    },

    async saveConfig() {
      if (!this.selectedUserId) {
        this.createNotificationError({ title: 'Sales Agent', message: 'Pick a user first.' });
        return false;
      }

      const pct = this.form.commissionPercentage !== null ? Number(this.form.commissionPercentage) : null;
      const disc = this.form.discountLimit !== null ? Number(this.form.discountLimit) : null;

      if (Number.isFinite(pct) && (pct < 0 || pct > 100)) {
        this.createNotificationError({ title: 'Sales Agent', message: 'Commission % must be 0–100.' });
        return false;
      }
      if (Number.isFinite(disc) && (disc < 0 || disc > 100)) {
        this.createNotificationError({ title: 'Sales Agent', message: 'Discount limit % must be 0–100.' });
        return false;
      }

      this.isLoading = true;
      try {
        if (!this.configRepository) {
          this.configRepository = this.repositoryFactory.create('salesrep_config');
        }

        const ctx = Shopware.Context.api;
        let entity = null;

        if (this.currentConfigId) {
          entity = await this.configRepository.get(this.currentConfigId, ctx);
        }
        if (!entity) {
          entity = this.configRepository.create(ctx);
        }

        entity.userId = this.selectedUserId;
        entity.commissionPercentage = pct;
        entity.discountLimit = disc;

        await this.configRepository.save(entity, ctx);

        this.currentConfigId = entity.id;
        this.createNotificationSuccess({ title: 'Sales Agent', message: 'Config saved.' });
        return true;
      } catch (e) {
        const detail =
          e?.response?.data?.errors?.[0]?.detail ||
          e?.response?.data?.errors?.[0]?.title ||
          e?.message || 'Unknown error';
        this.createNotificationError({ title: 'Sales Agent', message: `Failed to save config: ${detail}` });
        return false;
      } finally {
        this.isLoading = false;
      }
    },
  },
});
