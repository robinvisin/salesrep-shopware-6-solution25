const { Classes } = Shopware;
const ApiService = Classes.ApiService;

export default class AbandonedCartApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'abandoned-cart') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'abandonedCartApiService';
    }

    search(criteria = {}) {
        const headers = this.getBasicHeaders();
        return this.httpClient
            .post(`/_action/${this.name}/search`, { criteria }, { headers })
            .then((response) => ApiService.handleResponse(response));
    }
}
