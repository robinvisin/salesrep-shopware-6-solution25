const { Classes } = Shopware;
const ApiService = Classes.ApiService;

export default class AbandonedCartReminderService extends ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'sales-agent') {
    super(httpClient, loginService, apiEndpoint);
  }

  sendReminder(payload) {
    return this.httpClient.post(
      '/_action/sales-agent/abandoned-cart/reminder',
      payload,
      { headers: this.getBasicHeaders() }
    );
  }
}
