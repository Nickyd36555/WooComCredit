# Simple Store Credit for WooCommerce

A tiny, single-file WooCommerce plugin for gifting store credit to customers.
Customers see their balance under **My Account → Store Credit** and can apply
it to any order at checkout — right away or whenever they feel like it.

## Features

- **Store Credit tab in My Account** (placed right after Orders) showing the
  current balance and a full credit history (gifts, spends, refunds).
- **Gift/adjust credit from the admin** — a *WooCommerce → Store Credit* page
  with a customer search box, add/deduct actions, an optional note that the
  customer sees in their history, and a list of every customer holding credit.
- **Daily giveaway** — pick 5 customers at random from yesterday's paid
  orders, assign each a random credit amount within a range you choose
  (editable per winner), and send with one click. Guest winners get an
  account created automatically.
- **Email notifications** — optionally email the customer when you gift them
  credit (on by default), using your store's standard WooCommerce email
  template with the amount, your note, their new balance, and a link to
  their Store Credit page.
- **Redeem at checkout** — customers tick "Use my store credit" at checkout
  (or use the Apply link on the cart page). The credit is deducted from the
  final order total and can cover the entire order — items, shipping, taxes,
  and other charges — capped at the order cost so the total never goes
  negative. A "Store credit −$x" row appears in the cart/checkout totals and
  on order confirmations and emails.
- **See credit used on orders** — a "Store credit" column in the Orders list
  shows how much credit was redeemed on each order, and the order edit screen
  shows the amount redeemed at the top of its Store Credit box (flagged if the
  order was cancelled/refunded and the credit returned).
- **Automatic bookkeeping** — credit is deducted when the order is placed and
  automatically returned to the customer if the order is cancelled, fails, or
  is fully refunded.
- HPOS (High-Performance Order Storage) compatible.

## Automatic updates

Once installed, the plugin checks its public GitHub repository for new versions
and shows a normal **update available** notice on your **Plugins** screen —
click **Update** like any other plugin. No tokens or configuration required.

To publish a new version: bump the `Version:` header in the main plugin file
and push it to the tracked branch — that's it. Sites compare their installed
version against the one on the branch and offer an update when it's higher.
Sites see the update within a few hours (WordPress caches update checks);
visiting **Dashboard → Updates** and clicking **Check again** forces an
immediate check.

## Installation

1. Copy the `woocommerce-simple-store-credit.php` file into
   `wp-content/plugins/woocommerce-simple-store-credit/` on your site
   (or zip the folder and upload it via **Plugins → Add New → Upload Plugin**).
2. Activate **Simple Store Credit for WooCommerce** on the Plugins screen.
3. Go to **WooCommerce → Store Credit**, search for a customer, enter an
   amount, and click **Update credit**.

> **Note:** the checkout checkbox is built for the classic checkout
> (`[woocommerce_checkout]` shortcode). If your theme uses the block-based
> checkout, customers can still apply their credit from the cart page.

## How credit is stored

Balances and history are stored as user meta (`_wcsc_credit_balance` and
`_wcsc_credit_log`), and each order that spends credit records the amount in
its own meta (`_wcsc_credit_used`) so refunds/cancellations can return it.
No custom database tables are created.
