const { Classes } = Shopware;
const ApiService = Classes.ApiService;
export default class SalesrepOrderClaimApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/salesrep/order') {
        super(httpClient, loginService, apiEndpoint);
    }

    claim({ orderId, agentUserId, reason }) {
        const route = `${this.getApiBasePath()}/claim`;

        return this.httpClient
            .post(
                route,
                {
                    orderId,
                    agentUserId: agentUserId || null,
                    reason: (reason || '').toString(),
                },
                { headers: this.getBasicHeaders() }
            )
            .then(ApiService.handleResponse);
    }
}
