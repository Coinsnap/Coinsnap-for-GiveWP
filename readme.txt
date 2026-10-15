=== Bitcoin payment for GiveWP ===

Contributors: coinsnap
Tags: bitcoin, lightning, givewp, donations, payment gateway
Requires at least: 6.0
Tested up to: 7.0.6
Requires PHP: 7.4
Stable tag: 1.4.7
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin and Lightning donations in GiveWP forms and fundraising campaigns with Coinsnap or your own BTCPay Server.

== Description ==

= Add Bitcoin and Lightning donations to GiveWP =

Coinsnap for GiveWP adds Bitcoin as an additional payment method to your existing GiveWP donation and fundraising workflow.

Supporters can donate with **Bitcoin via Lightning or on-chain** while you continue using GiveWP for donation forms, fundraising campaigns and donor administration.

Bitcoin can be offered alongside existing payment methods such as cards or PayPal, or used as a standalone donation option.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

= Key features =

* Accept **Bitcoin and Lightning donations** in GiveWP
* Support for **Lightning and Bitcoin on-chain**
* Works with existing GiveWP donation and fundraising forms
* Supports simple, preset and multi-step GiveWP donation flows
* Bitcoin can be offered alongside existing payment methods
* Support for **Coinsnap** and **BTCPay Server**
* BTCPay Server setup wizard
* Automatic donation-status updates after payment
* Custom redirect after payment
* No own Lightning node required when using Coinsnap
* Non-custodial / self-custody payment model
* Optional bank settlement through supported Coinsnap partners
* Open source and free to install

= Typical use cases =

Use Coinsnap for GiveWP for:

* nonprofits and NGOs
* foundations and charities
* creators
* open-source projects
* community fundraising
* grassroots campaigns
* one-time donations
* international fundraising
* small and spontaneous contributions

= How it works =

1. Install and activate GiveWP.
2. Install and activate Coinsnap for GiveWP.
3. Open the GiveWP payment settings.
4. Select Coinsnap or BTCPay Server.
5. Enter the required connection details.
6. Enable Bitcoin as a GiveWP payment method.
7. Create or edit your GiveWP donation form or campaign.
8. Make a test Bitcoin payment before going live.

= Coinsnap or BTCPay Server =

The add-on supports:

* **Coinsnap** — accept Bitcoin without operating your own Bitcoin or Lightning payment infrastructure.
* **BTCPay Server** — connect your own self-hosted BTCPay Server.

= GiveWP integration =

Coinsnap works as a GiveWP payment gateway.

You keep using GiveWP for the donation experience while Coinsnap handles the Bitcoin payment. After a successful payment, the corresponding GiveWP donation status is updated automatically.

The integration can be used with GiveWP donation flows including fixed amounts, preset donation levels and multi-step forms.

= Settlement =

With Coinsnap, donations can be settled to a compatible Bitcoin or Lightning wallet.

One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin donations can also be automatically converted and settled to a bank account.

= Fees =

Coinsnap charges a **1% transaction fee**. The fee is handled separately through the merchant's Coinsnap balance and is not deducted from the Bitcoin donation itself.

= More information =

* Product page: https://coinsnap.io/wp-add-ons/givewp/
* User Guide: https://coinsnap.io/user-guides/givewp-bitcoin-user-guide/
* WordPress Plugin Directory: https://wordpress.org/plugins/coinsnap-for-givewp/
* Demo: https://givewp.coinsnap.org/
* GitHub: https://github.com/Coinsnap/Coinsnap-for-GiveWP
* Coinsnap Wallet: https://coinsnap.io/wallet/
* Coinsnap API: https://docs.coinsnap.io/
* Coinsnap account: https://app.coinsnap.io/register

== Installation ==

1. Install and activate **GiveWP**.
2. In WordPress, go to **Plugins → Add New**.
3. Search for **Bitcoin payment for GiveWP** or **Coinsnap for GiveWP**.
4. Install and activate the Coinsnap add-on.
5. Open the GiveWP payment settings.
6. Select Coinsnap or BTCPay Server and connect the provider.
7. Enable the Bitcoin payment method.
8. Create or edit a GiveWP donation form.
9. Make a test Bitcoin payment.

== Frequently Asked Questions ==

= Can GiveWP accept Bitcoin donations? =

Yes. Coinsnap for GiveWP adds Bitcoin and Lightning as payment options to GiveWP forms and fundraising campaigns.

= Can donors pay with both Lightning and Bitcoin on-chain? =

Yes. The integration supports both Lightning and Bitcoin on-chain.

= Can Bitcoin be offered alongside Stripe or PayPal? =

Yes. Bitcoin can be offered alongside the other payment methods configured in GiveWP.

= Can I use my own BTCPay Server? =

Yes. BTCPay Server is supported as an alternative to Coinsnap.

= Does it work with different GiveWP form types? =

Yes. The current Coinsnap product documentation describes support for fixed, preset and multi-step GiveWP donation flows.

= Do donors need a Coinsnap account? =

No. Donors only need a compatible Bitcoin or Lightning wallet.

= Do I need my own Lightning node? =

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

= Does Coinsnap hold my Bitcoin donations? =

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

= Can donations be settled to a bank account? =

Depending on the merchant setup and supported partner integrations, Bitcoin donations can be converted and settled to a bank account.

= What does Coinsnap cost? =

Coinsnap charges a 1% transaction fee.

== Screenshots ==

1. Install Bitcoin payment for GiveWP from the WordPress Plugin Directory
2. GiveWP payment-gateway settings
3. Coinsnap payment-provider settings
4. BTCPay Server payment-provider settings
5. BTCPay Server setup wizard
6. Coinsnap connection status
7. GiveWP donation form with Bitcoin payment
8. Bitcoin and Lightning payment QR code
9. Successful GiveWP donation

== Changelog ==

= 1.4.7 :: 2026-05-24 =
* Compatibility with GiveWP 4.15.2 is tested.
* Compatibility with WordPress 7.0 is tested.

= 1.4.6 :: 2026-04-07 =
* Updated Coinsnap Invoice client.
* Updated Coinsnap Store client.
* Compatibility with GiveWP 4.14.4 is tested.
* Compatibility with WordPress 6.9.4 is tested.

= 1.4.5 :: 2026-03-06 =
* Updated plugin description.
* Compatibility with GiveWP 4.14.0 - 4.14.2 is tested.

= 1.4.4 :: 2025-12-28 =
* Compatibility with GiveWP 4.13.2 is tested.
* Updated PHP files protection.
* Updated dependency functions naming.

= 1.4.3 :: 2025-12-04 =
* Compatibility with WordPress 6.9 is tested.
* Compatibility with GiveWP 4.13.1 is tested.

= 1.4.2 :: 2025-10-12 =
* Added handling for currencies not supported by BTCPay Server.
* Added custom redirect after payment.

= 1.4.1 :: 2025-10-03 =
* Fixed webhook payload handling and donation-status updates.
* Compatibility with GiveWP 4.10.1 is tested.

= 1.3.1 :: 2025-07-25 =
* Updated Coinsnap WordPress library.
* Improved BTCPay Server API-key setup.
* Added nonce validation to the BTCPay Server setup flow.

= 1.3.0 :: 2025-06-22 =
* Improved BTCPay Server setup callback handling.
* Added webhook data storage and webhook-secret validation.

= 1.2.0 :: 2025-05-13 =
* Added BTCPay Server as a Bitcoin payment gateway.
* Added BTCPay Server setup wizard.
* Added minimum-payment validation.

= 1.1.0 :: 2025-04-02 =
* Added automatic redirect after payment.
* Added minimum-payment validation.

= 1.0.2 :: 2025-03-01 =
* Prevented plugin activation when GiveWP is not installed and active.
* Improved connection exception handling.

= 1.0.1 :: 2025-02-09 =
* Added GiveWP identification to the Coinsnap server connection status.

= 1.0.0 :: 2024-01-20 =
* Initial release.
