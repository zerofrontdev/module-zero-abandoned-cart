# Changelog: zerofront/module-zero-abandoned-cart

## 1.3.6

- **Behaviour change:** a guest's cart gets a reminder only when its email is a confirmed newsletter subscriber on the cart's website. Customers' carts are reminded as before. There is no setting that brings back reminders to every guest: an email typed at checkout is not consent to marketing.
- New setting **Guest Carts** (Stores > Configuration > Zero > Abandoned Cart Reminders): Only Newsletter Subscribers (the default) or Never.
- Every reminder has an "Unsubscribe from cart reminders" link and the `List-Unsubscribe` and `List-Unsubscribe-Post` headers, so mail apps show their own Unsubscribe button. Opening the link only asks to confirm; a mail scanner opening it unsubscribes nobody.
- An unsubscribed email gets no more reminders on that website (kept as a hash, not the plain address); the newsletter subscription is not touched.
- A template copied into Marketing > Email Templates before this version has no unsubscribe link in its body: add `<a href="{{var unsubscribe_url}}">…</a>`.
- `setup:upgrade` adds the tables `zero_abandoned_cart_unsubscribe` and `zero_abandoned_cart_optout`. Run `bin/magento zero:abandoned-cart:send --dry-run` before and after the update to see what changes for your store.

Earlier releases (1.3.5 and before): see the Git tags.
