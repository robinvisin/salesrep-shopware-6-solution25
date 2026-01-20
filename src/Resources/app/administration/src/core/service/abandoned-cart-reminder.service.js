const { Classes } = Shopware;
const ApiService = Classes.ApiService;

export default class AbandonedCartReminderService extends ApiService {
  constructor(httpClient, loginService, apiEndpoint = 'salesrep') {
    super(httpClient, loginService, apiEndpoint);
  }

  sendReminder(payload) {
    return this.httpClient.post(
      '/_action/salesrep/abandoned-cart/reminder',
      payload,
      { headers: this.getBasicHeaders() }
    );
  }
}
