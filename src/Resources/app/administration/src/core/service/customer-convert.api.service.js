const { ApiService } = Shopware.Classes;

export default class CustomerConvertApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'sales-agent-customer-convert');
    }

    convertGuest(customerId) {
        const headers = this.getBasicHeaders();

        return this.httpClient.post(
            '/_action/sales-agent/customer/convert-guest',
            { customerId },
            { headers },
        );
    }
}
