[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25com/sales-agent-shopware-6-solution25/blob/main/LICENSE)

# Sales Agent for Shopware 6

## Introduction

The **Sales Agent Plugin** turns Shopware 6 admin users into fully trackable sales agents. Each agent can log in to the Admin, impersonate their assigned customers, place orders on their behalf, negotiate custom product and shipping prices within configurable discount limits, and earn commission automatically calculated on every eligible order.

This version of the plugin extends the core agent functionality with a comprehensive **Order Claim system**, **split commission** support, **agent-only category access control**, **abandoned cart email reminders**, **guest customer conversion**, **ZIP code auto-fill**, and **automatic unpaid order cancellation** — making it a full-featured sales operations platform built directly into Shopware.

---

## Key Features

### Agent Customer Impersonation
- Admin users acting as sales agents can **impersonate their assigned customers** directly from the Admin, placing orders on their behalf through the storefront with full customer context.

### Custom Price Override
- Sales agents can **override product unit prices** on individual line items at checkout, within a configurable maximum discount limit.
- If the entered price exceeds the allowed discount, the cart is blocked with a clear error — preventing unauthorized discounts.
- Per-line-item approval overrides with expiry timestamps can be granted for exceptional cases.

### Custom Shipping Price Override
- Agents can also **override the shipping cost** on an order within their allowed discount limit, giving flexibility for negotiated shipping arrangements.

### Automatic Commission Calculation
- Commission is **automatically calculated and saved** when an order is placed by a sales agent, based on a configurable percentage of the order total.
- Commission is **automatically revoked** when an order is cancelled, its payment fails, it is refunded (fully or partially), or the delivery is returned.

### Split Commission
- Agents can **split commission** on an order with a second agent by specifying an email address and a percentage split directly from the storefront checkout.
- The split is stored on the order custom fields and visible in the Admin order detail view.
- A dedicated **Split Commission** tab is available on the order detail page in the Admin.

### Order Claim System
- Sales agents can **claim existing orders** that were placed without an agent, assigning themselves or another agent to the order for commission purposes.
- A **claim request workflow** is available: agents submit a claim request with an optional reason, which a manager can then **approve or reject** from the Admin.
- Claiming or unclaiming an order automatically triggers a **commission recalculation**.
- A dedicated **Claim Requests** section in the Admin lists all pending, approved, and rejected requests.

### Commission Rules
- Administrators can create named **commission rules** with their own percentage and maximum discount percentage.
- Rules can **exclude specific agents** from their scope via a many-to-many agent mapping.

### Per-Agent Configuration
- Each agent can have an individual **commission percentage** and **discount limit** configured separately from the global defaults.

### Agent-Only Category Access
- Categories can be flagged as **agent-only** via a custom field.
- Non-agent storefront users attempting to access these categories or their products are automatically **blocked and redirected**.
- Agent-only category IDs are cached for 1 hour for performance.
- Orders containing products from agent-only categories are **hidden from the standard order list** for non-agent customers.

### Sales King Dashboard
- A dedicated **Admin dashboard** (Sales King) gives each agent a real-time overview of:
    - KPI cards (linked customers, current orders)
    - Paginated orders grid with status, total, and payment method
    - Paginated customers grid with contact and group info
    - Commission chart (monthly trend)
    - All-time earnings view with year selector and CSV export

### Abandoned Cart Tracking & Email Reminders
- The plugin automatically detects and tracks **abandoned carts** for logged-in customers.
- Abandoned carts are visible in the Admin under a dedicated **Abandoned Carts** section, with:
    - Line item detail view
    - Order creation directly from the cart
    - Cart deletion
    - **Send Reminder** — triggers an email to the customer with optional notes and cart item summary
- Three scheduled tasks run automatically: **mark**, **update**, and **delete** abandoned carts.

### Guest Customer Conversion
- Administrators can convert a **guest checkout customer** into a registered account directly from the Admin.
- A password recovery email is automatically sent to the customer after conversion.

### ZIP Code Auto-Fill
- In address forms, entering a 5-digit US ZIP code automatically **fills in the city and state** using the Zippopotam.us API.

### Cart Notes
- Sales agents can attach **order notes** and a **Do Not Ship Until** date to a cart before checkout, which are carried through to the order.

### Automatic Unpaid Order Cancellation
- A scheduled task runs **every hour** to automatically cancel orders that remain unpaid beyond a configurable threshold, keeping the order backlog clean.

### Agent Email Validation
- A dedicated endpoint allows the Admin to **validate whether an email address belongs to a configured sales agent**, used for split commission and claim workflows.

### Excluded Emails
- A global comma-separated **excluded emails** list prevents specific agent accounts from earning commissions regardless of orders placed.

### Role-Based Access Control
- Fine-grained **ACL privileges** control which admin users can view the Sales King dashboard, manage commissions, access configurations, override prices, manage claim requests, and view abandoned carts.

### Multi-Language Support
- Admin interface available in **English and German**.

---

## Compatibility
- ✅ Shopware 6.6.x
- ✅ PHP 8.1+

---

## Get Started

### Installation & Activation

#### GitHub

1. Clone the plugin into your Shopware plugins directory:

```bash
git clone https://github.com/solution25com/sales-agent-shopware-6-solution25.git custom/plugins/SalesAgent
```

2. **Install the Plugin in Shopware 6**

    - Log in to your Shopware 6 Administration panel.
    - Navigate to **Extensions > My Extensions**.
    - Locate the plugin and click **Install**.

3. **Activate the Plugin**

    - After installation, click **Activate** to enable the plugin.
    - Run the following commands from your Shopware root:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SalesAgent
bin/console cache:clear
```

4. **Build Administration & Storefront Assets**

```bash
bin/console bundle:dump
bin/build-administration.sh
bin/build-storefront.sh
bin/console cache:clear
```

5. **Verify Installation**

    - After activation, you will see **Sales Agent Plugin for Shopware 6** in the list of installed plugins.

---

## Plugin Configuration

After installing the plugin, configure global defaults and per-agent settings through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Settings > Sales Agent**
2. Select the **Sales Channel** you want to configure
3. Set the following fields:

### Global Configuration

| Field | Description |
|---|---|
| **Total Commission Percentage (%)** | Default commission rate applied to eligible orders for all agents |
| **Agent Discount Limit (%)** | Maximum discount an agent is allowed to apply to product prices. Orders exceeding this are excluded from commission |

### Per-Agent Configuration

| Field | Description |
|---|---|
| **Select Agent User** | Select a specific admin user to configure individually |
| **Commission Percentage (%)** | Per-agent commission rate — overrides the global default when set |
| **Discount Limit (%)** | Per-agent maximum discount — overrides the global default when set |

### Excluded Emails

| Field | Description |
|---|---|
| **Excluded Emails** | Comma-separated list of agent email addresses excluded from all commission calculations globally |

---

## How It Works

### 1. Agent Places an Order for a Customer

A sales agent logs in to the Shopware Admin and navigates to a customer's profile. From there, they can impersonate the customer and place an order on their behalf through the storefront. The agent's identity is tracked via the impersonation context throughout the session.

### 2. Custom Price & Shipping Negotiation

While building the order, the agent can modify individual **product unit prices** and the **shipping cost** directly in the storefront checkout. Each change is validated against the agent's configured discount limit. Exceeding the limit blocks the cart and displays an error message.

### 3. Split Commission

If an agent shares the deal with a colleague, they can enter the second agent's email and a commission percentage split in the checkout. Both agents' commission shares are stored on the order and visible in the Admin on the **Split Commission** tab of the order detail.

### 4. Commission on Order Placement

When the order is saved, the plugin automatically calculates and records the agent's commission. If a commission was split, both shares are recorded separately. Commission is revoked automatically on cancellation, refund, or return.

### 5. Order Claim

For orders placed directly by customers without an agent, a sales agent can submit a **claim request** from the Admin order detail view. A manager reviews the request and approves or rejects it. On approval, the agent is assigned to the order and commission is recalculated. Managers can also directly claim or unclaim orders without a request workflow.

### 6. Agent-Only Categories

Categories flagged as **agent-only** are invisible and inaccessible to regular customers on the storefront. Only impersonating agents or logged-in users with the agent role can browse these categories and their products.

### 7. Abandoned Cart Recovery

The plugin tracks carts left inactive by logged-in customers. From the Admin Abandoned Carts section, an agent can view the cart contents, send a personalized reminder email to the customer, convert the cart into an order, or delete it.

### 8. Guest Conversion

When a customer checked out as a guest and the agent wants to convert them to a registered account, the Admin provides a one-click **Convert Guest** action that creates a full customer account and sends a password recovery email.

### 9. Automatic Unpaid Order Cancellation

A scheduled task runs every hour scanning for unpaid orders. Orders that remain unpaid beyond the configured threshold are automatically cancelled, keeping the order backlog clean without manual intervention.

---

## Admin Sections Added by This Plugin

| Section | Location | Description |
|---|---|---|
| **Sales King Dashboard** | Admin sidebar | Agent-facing dashboard with KPIs, orders, customers, and commission charts |
| **All-Time Earnings** | Sales King Dashboard | Full commission history with year filter and CSV export |
| **Abandoned Carts** | Admin sidebar | Abandoned cart list with reminder email, order creation, and deletion |
| **Claim Requests** | Admin sidebar | List of order claim requests with approve/reject workflow |
| **Split Commission tab** | Order detail view | Shows commission split details per order |
| **Sales Agent tab** | Customer detail view | Shows the sales agent linked to a customer |
| **Sales Agent settings** | Settings menu | Global and per-agent commission and discount configuration |
| **Notes tab** | Order creation & detail | Agent can add order notes and Do Not Ship Until date |

---

## Uninstallation

```bash
bin/console plugin:deactivate SalesAgent
bin/console plugin:uninstall SalesAgent
bin/console cache:clear
```

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Support

For questions or issues, please open a [GitHub Issue](https://github.com/solution25com/sales-agent-shopware-6-solution25/issues) or contact [Solution25](https://solution25.com).