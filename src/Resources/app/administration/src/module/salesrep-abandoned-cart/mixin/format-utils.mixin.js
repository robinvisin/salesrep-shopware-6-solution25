const { Component, Mixin, Data: { Criteria } } = Shopware;
export default {

    methods: {
      formatCurrency(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '$0.00';
  
        const truncated = Math.trunc(n * 100) / 100;
  
        return new Intl.NumberFormat('en-US', {
          style: 'currency',
          currency: 'USD',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }).format(truncated);
      },
      formatDate(dateString) {
        if (!dateString) return "";
        return new Intl.DateTimeFormat("en-US", {
          year: "numeric", month: "short", day: "2-digit",
          hour: "2-digit", minute: "2-digit",
        }).format(new Date(dateString));
      },
      createCriteria() {
        const criteria = new Criteria(this.page, this.limit);
        criteria.addSorting(Criteria.sort('createdAt', 'DESC'));
        return criteria;
      },
      async findCustomerIdsByEmail(term) {
        const t = (term || '').trim();
        if (!t || !t.includes('@')) return [];
      
        const c = new Criteria(1, 50);
        c.addFilter(Criteria.contains('email', t));
      
        const customers = await this.customerRepository.search(c, Shopware.Context.api);
        return customers.map(cu => cu.id).filter(Boolean);
      },
    }
  }