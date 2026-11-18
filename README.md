# Coinsnap for GiveWP

![Coinsnap for GiveWP Add-on](https://resources.coinsnap.org/products/givewp/images/screenshot-1.png)

**Bitcoin payment add-on for GiveWP — accept Bitcoin and Lightning donations in GiveWP forms and fundraising campaigns with Coinsnap or your own BTCPay Server.**

Coinsnap for GiveWP adds Bitcoin as an additional payment method to the existing GiveWP donation and fundraising workflow.

Supporters can donate with **Bitcoin via Lightning or on-chain** while organizations continue using GiveWP for donation forms, campaigns, donor data and fundraising administration. Bitcoin can be offered alongside existing payment methods such as cards or PayPal, or used as a standalone payment option.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning donations** in GiveWP
- Support for **Lightning and Bitcoin on-chain**
- Works with existing GiveWP donation and fundraising forms
- Compatible with simple, preset and multi-step GiveWP donation flows
- Bitcoin can be offered alongside existing payment methods
- Support for **Coinsnap** and **BTCPay Server**
- BTCPay Server setup wizard
- Automatic payment and donation-status updates
- Custom redirect after payment
- No own Lightning node required when using Coinsnap
- **Non-custodial / self-custody** payment model
- Optional settlement to a bank account through supported Coinsnap partners
- Open source and free to install

## Current public release

The current WordPress.org release is **1.4.7**.

WordPress.org currently lists:

- WordPress **6.0 or newer**
- PHP **7.4 or newer**
- Tested up to **WordPress 7.0.6**
- GiveWP compatibility tested up to **4.15.2**

> **Developer note:** the current GitHub `main` branch still identifies the plugin as version **1.4.6**, tested with WordPress 6.9 and GiveWP 4.14.4. The repository code metadata and the public WordPress.org release should be synchronized separately.

## Typical use cases

Coinsnap for GiveWP is suitable for:

- nonprofits and NGOs
- foundations
- charities
- creators
- open-source projects
- community organizations
- grassroots fundraising
- one-time donation campaigns
- international fundraising
- small and spontaneous contributions

## How it works

1. Install and activate **GiveWP**.
2. Install and activate **Coinsnap for GiveWP**.
3. Open the GiveWP payment-gateway settings.
4. Select **Coinsnap** or **BTCPay Server**.
5. Enter the required connection details.
6. Enable Bitcoin as a payment option for your GiveWP forms.
7. Create or edit a GiveWP donation or fundraising form.
8. Publish the form and make a test Bitcoin payment.

## Coinsnap or BTCPay Server

The add-on supports two payment-provider modes:

- **Coinsnap** — for organizations that want to accept Bitcoin without operating their own Bitcoin or Lightning payment infrastructure.
- **BTCPay Server** — for organizations that want to connect their own self-hosted BTCPay Server.

## GiveWP integration

Coinsnap works as a GiveWP payment gateway. This means you keep using GiveWP for the donation experience while Coinsnap handles the Bitcoin payment.

The integration supports GiveWP donation and fundraising flows including fixed amounts, preset donation levels and multi-step forms.

After a successful Bitcoin payment, the corresponding GiveWP donation status is updated automatically.

## Settlement

With Coinsnap, donations can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a **non-custodial / self-custody** approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin donations can also be automatically converted and settled to a bank account.

## Fees

Coinsnap charges a **1% transaction fee**. Coinsnap does not deduct this fee from the Bitcoin payment itself; fees are handled separately through the merchant's Coinsnap balance.

## Installation

### Install from WordPress.org

1. In WordPress, go to **Plugins → Add New**.
2. Install and activate **GiveWP** if it is not already installed.
3. Search for **Bitcoin payment for GiveWP** or **Coinsnap for GiveWP**.
4. Install and activate the Coinsnap add-on.
5. Open the GiveWP payment settings.
6. Connect Coinsnap or BTCPay Server.
7. Enable the Bitcoin payment method.
8. Make a test donation before going live.

### Install from GitHub

1. Download this repository as a ZIP archive.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload, install and activate the add-on.
4. Make sure GiveWP is installed and active.
5. Configure Coinsnap or BTCPay Server in the GiveWP payment settings.

## Resources

- [Coinsnap for GiveWP product page](https://coinsnap.io/wp-add-ons/givewp/)
- [GiveWP Bitcoin User Guide](https://coinsnap.io/user-guides/givewp-bitcoin-user-guide/)
- [WordPress Plugin Directory](https://wordpress.org/plugins/coinsnap-for-givewp/)
- [GiveWP demo donation page](https://givewp.coinsnap.org/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### Can GiveWP accept Bitcoin donations?

Yes. Coinsnap for GiveWP adds Bitcoin and Lightning as payment options to GiveWP donation forms and fundraising campaigns.

### Can donors pay with both Lightning and Bitcoin on-chain?

Yes. The integration supports both Lightning and Bitcoin on-chain.

### Can Bitcoin be offered alongside Stripe or PayPal?

Yes. Bitcoin can be added alongside the other payment methods configured in GiveWP.

### Can I use my own BTCPay Server?

Yes. BTCPay Server is supported as an alternative to Coinsnap.

### Does it work with different GiveWP form types?

Yes. The current Coinsnap product documentation describes support for GiveWP donation flows including fixed, preset and multi-step forms.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Does Coinsnap hold the donations?

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

### Can donations be settled to a bank account?

Depending on the merchant setup and supported partner integrations, Bitcoin donations can be automatically converted and settled to a bank account.

### What does Coinsnap cost?

Coinsnap charges a 1% transaction fee.

### Is the add-on open source?

Yes. The add-on is open source under GPL-2.0.

## Changelog

### 1.4.7 — 2026-05-24
- Compatibility with GiveWP 4.15.2 tested.
- Compatibility with WordPress 7.0 tested.

### 1.4.6 — 2026-04-07
- Updated the Coinsnap Invoice client.
- Updated the Coinsnap Store client.
- Compatibility with GiveWP 4.14.4 tested.
- Compatibility with WordPress 6.9.4 tested.

### 1.4.5 — 2026-03-06
- Updated plugin description.
- Compatibility with GiveWP 4.14.0–4.14.2 tested.

### 1.4.4 — 2025-12-28
- Compatibility with GiveWP 4.13.2 tested.
- Updated PHP file protection and dependency-function naming.

### 1.4.3 — 2025-12-04
- Compatibility with WordPress 6.9 tested.
- Compatibility with GiveWP 4.13.1 tested.

### 1.4.2 — 2025-10-12
- Added handling for currencies not supported by BTCPay Server.
- Added custom redirect after payment.

### 1.4.1 — 2025-10-03
- Fixed webhook handling and donation-status updates.
- Compatibility with GiveWP 4.10.1 tested.

### 1.3.1 — 2025-07-25
- Updated the Coinsnap WordPress library.
- Improved BTCPay Server API-key setup.
- Added nonce validation to the BTCPay setup flow.

### 1.3.0 — 2025-06-22
- Improved BTCPay setup callback handling.
- Added webhook data storage and webhook-secret validation.

### 1.2.0 — 2025-05-13
- Added BTCPay Server as a payment gateway.
- Added the BTCPay Server setup wizard.
- Added minimum-payment validation.

### 1.1.0 — 2025-04-02
- Added automatic redirect option after payment.
- Added minimum-payment validation.

### 1.0.0 — 2024-01-20
- Initial release.
