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
  
      formatDate(date) {
        if (!date) return '';
  
        const options = {
          day: '2-digit',
          month: 'long',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          hour12: false,
        };
  
        const formatted = new Intl.DateTimeFormat('en-GB', options).format(new Date(date));
        return formatted.replace(',', ' at');
      }
    }
  };
  