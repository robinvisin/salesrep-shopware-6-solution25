const { ApiService } = Shopware.Classes;

export default class OrderClaimRequestApiService extends ApiService {
    constructor(httpClient, loginService) {
        super(httpClient, loginService, 'sales-agent-order-claim');
    }

    approve(requestId, decisionNote = null) {
        const headers = this.getBasicHeaders();

        const data = { requestId };
        if (decisionNote !== null && String(decisionNote).trim() !== '') {
            data.decisionNote = String(decisionNote).trim();
        }

        return this.httpClient.post(
            '/_action/sales-agent/order-claim/approve',
            data,
            { headers }
        );
    }

    reject(requestId, decisionNote = null) {
        const headers = this.getBasicHeaders();

        const data = { requestId };
        if (decisionNote !== null && String(decisionNote).trim() !== '') {
            data.decisionNote = String(decisionNote).trim();
        }

        return this.httpClient.post(
            '/_action/sales-agent/order-claim/reject',
            data,
            { headers }
        );
    }

    request(orderId, reason = null) {
        const headers = this.getBasicHeaders();

        const data = { orderId };
        if (reason !== null && String(reason).trim() !== '') {
            data.reason = String(reason).trim();
        }

        return this.httpClient.post(
            '/_action/sales-agent/order-claim/request',
            data,
            { headers }
        );
    }
}
