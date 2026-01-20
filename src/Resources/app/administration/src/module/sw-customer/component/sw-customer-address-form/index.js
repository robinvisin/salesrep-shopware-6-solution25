import template from './sw-customer-address-form.html.twig';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-customer-address-form', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            isLoadingZip: false,
        };
    },

    async created() {
        await this.initDefaultCountry();
    },

    methods: {
        async initDefaultCountry() {
            if (this.countryId) {
                return;
            }

            const countryRepo = this.repositoryFactory.create('country');
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('iso', 'US'));

            const result = await countryRepo.search(criteria, Shopware.Context.api);
            const us = result.first();
            if (!us) {
                return;
            }

            this.countryId = us.id;

            if (typeof this.onChangeCountry === 'function') {
                this.onChangeCountry(us.id);
            }
        },

        async onZipcodeChange(value) {
            let raw = '';

            if (typeof value === 'string') {
                raw = value;
            } else if (value && value.target && typeof value.target.value === 'string') {
                raw = value.target.value;
            } else if (this.address && typeof this.address.zipcode === 'string') {
                raw = this.address.zipcode;
            } else {
                raw = '';
            }

            const clean = raw.trim();
            if (!clean) {
                return;
            }

            const plainZip = clean.split('-')[0];
            if (plainZip.length < 5) {
                return;
            }

            this.isLoadingZip = true;

            try {
                const res = await fetch(`https://api.zippopotam.us/us/${plainZip}`);
                if (!res.ok) {
                    return;
                }

                const data = await res.json();
                if (!data.places || !data.places.length) {
                    return;
                }

                const place = data.places[0];

                if (place['place name'] && !this.address.city) {
                    this.address.city = place['place name'];
                }

                if (!this.countryId) {
                    await this.initDefaultCountry();
                }

                const abbr = place['state abbreviation'];
                if (abbr) {
                    await this.setStateByAbbreviation(abbr);
                }
            } finally {
                this.isLoadingZip = false;
            }
        },

        async setStateByAbbreviation(abbr) {
            if (!this.countryId) {
                await this.initDefaultCountry();
            }

            const stateRepo = this.repositoryFactory.create('country_state');
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('countryId', this.countryId));

            const states = await stateRepo.search(criteria, Shopware.Context.api);
            const lower = abbr.toLowerCase();

            const match = states.find((st) => {
                if (st.shortCode && st.shortCode.toLowerCase() === `us-${lower}`) {
                    return true;
                }
                if (st.name && st.name.toLowerCase() === lower) {
                    return true;
                }
                return false;
            });

            if (match) {
                this.address.countryStateId = match.id;
            }
        }
    }
});
