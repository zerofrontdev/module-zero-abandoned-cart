# Zero_AbandonedCart

One reminder email for a cart left with items in it: the items, a link back
to the cart, and a link to stop these reminders. Only to an email that agreed
to hear from the store: a customer's, or a guest's who is subscribed to the
newsletter.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-abandoned-cart
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Who gets a reminder

A cart gets one reminder, once, when all of these hold:

- the store view is active and has the reminders on;
- the cart is active, has items and an email, and last changed between
  *Send After (minutes)* and *Skip Carts Older Than (days)* ago;
- the email has not unsubscribed on the store view's website (below);
- it is a **customer's cart** (the account still exists), or a **guest's
  cart** whose email is a confirmed newsletter subscriber
  (`newsletter_subscriber.subscriber_status = 1`) on a store view of the
  same website. "Not activated", "unsubscribed" and "unconfirmed"
  subscribers do not count. A cart of a deleted customer counts as a
  guest's.

*Guest Carts* sets the guest rule per store view: **Only Newsletter
Subscribers** (the default) or **Never (customers' carts only)**. There is
no "every guest" option: an email typed into the checkout box is not consent
to marketing, and it may be someone else's.

All of this is decided in the SQL of `Model\Reminders::due()`, before the
batch limit (100 carts per store view per run), so carts that do not qualify
never hold back the ones that do.

## Unsubscribe link

Every reminder has, at the bottom, "Don't want these reminders? Unsubscribe
from cart reminders", and the headers `List-Unsubscribe: <link>` and
`List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058), so Gmail,
Yahoo and others show their own Unsubscribe button.

- The link is `abandonedcart/unsubscribe/index/?token=<32 hex>`, a new
  random 128-bit token per email. Only its sha256 is stored (the Email Log
  of Zero_Mail redacts `?token=` in the body it keeps); it is not the
  restore link's token and opens no cart. It works for 365 days, whether the
  reminders are on or off, and after the cart is gone.
- **Opening the link changes nothing.** The token moves into the session,
  the browser comes back to `abandonedcart/unsubscribe/index` without it
  (`Referrer-Policy: no-referrer`, so it never sits in a page URL, a Referer
  or an analytics hit), and the page (NOINDEX, never cached) asks to
  confirm with a button (a POST with the form key). A mail scanner that
  opens links unsubscribes nobody.
- **The mailbox's one-click button** POSTs to the same link: no form key,
  no cookie, the token is the only credential. It answers `200
  Unsubscribed from cart reminders.` or `404`, never a redirect.
- Unknown links count against the visitor's address: after 20 in 10 minutes
  a one-click POST with an unknown link answers `429` instead of `404` until
  the window ends. A link that works is never counted and always works, from
  any address (the address follows `X-Forwarded-For`, which can be forged).
- An unsubscribed email is kept as `sha256(lower(trim(email)))` per website
  in `zero_abandoned_cart_optout`, for good (no plain email). It covers the
  guest's and the customer's carts with that email on that website; other
  websites are not affected. The link tokens (`zero_abandoned_cart_unsubscribe`)
  are purged by the cron after 365 days. Unsubscribing here does not touch
  the newsletter subscription.

The template variable is `unsubscribe_url`. **A template copied into the
admin (Marketing > Email Templates) before this version has no unsubscribe
link in its body**: add
`<a href="{{var unsubscribe_url}}">…</a>`. The `List-Unsubscribe` headers
are sent with any template.

## Upgrading from a version without consent

For a store that already sends reminders, after the upgrade:

- **guests who are not newsletter subscribers on the cart's website get no
  more reminders** (owner decision: no reminder without consent). Customers'
  carts are reminded of as before;
- every reminder carries the unsubscribe link and headers.

There is no setting that brings back reminders to every guest. To see what
the change means for a store, run `bin/magento zero:abandoned-cart:send
--dry-run` before and after. Two new tables come with `setup:upgrade`.

## Settings

Stores > Configuration > Zero > Abandoned Cart Reminders: Enabled (off by
default), Guest Carts, Send After (minutes), Skip Carts Older Than (days),
Email Sender, Email Template ("Abandoned Cart Reminder").

## Command line

- `bin/magento zero:abandoned-cart:send` sends what the cron (every 10
  minutes, `zero_abandoned_cart_send`) would send now.
- `--dry-run` lists the carts due and sends nothing.
- `--preview=<cart id>` prints the email of a cart that is due, without
  sending or recording it (both links carry a token of zeros that does
  nothing).

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
