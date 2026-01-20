import template from './salesrep-pagination.html.twig';
import './salesrep-pagination.scss';
const {Component} = Shopware;

Component.register('salesrep-pagination', {
template,
props: {
    totalItems: {
        type: Number,
        required: true
    },
    currentPage: {
        type: Number,
        required: true
    },
    limit: {
        type: Number,
        required: true
    }
},

computed: {
    totalPages() {
        return Math.ceil(this.totalItems / this.limit);
    },
    hasPrevious() {
        return this.currentPage > 1;
    },
    hasNext() {
        return this.currentPage < this.totalPages;
    },
    visiblePages() {
        const total = this.totalPages;
        const current = this.currentPage;
        const delta = 2;
        const range = [];

        const left = Math.max(2, current - delta);
        const right = Math.min(total - 1, current + delta);

        range.push(1);

        if (left > 2) {
            range.push('...');
        }

        for (let i = left; i <= right; i++) {
            range.push(i);
        }

        if (right < total - 1) {
            range.push('...');
        }

        if (total > 1) {
            range.push(total);
        }

        return range.map((p, idx) => {
            return typeof p === 'number'
                ? { number: p, key: `page-${p}`, ellipsis: false }
                : { ellipsis: true, key: `ellipsis-${idx}` };
        });
    }
},

methods: {
    goToPage(page) {
        if (page < 1 || page > this.totalPages || page === this.currentPage) return;
        this.$emit('page-change', page);
    }
}
})