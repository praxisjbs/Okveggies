# Going live on Paystack, with the client on a shared screen

A runbook for the handover call. Follow it in order. The whole thing is about
ten minutes, and most of that is the client finding their own dashboard.

---

## Before you share your screen

**The secret key must not be read aloud or left visible on a shared screen.**
Anyone who sees it can charge cards on the client's account and read every
transaction. A screen share is often recorded, and a recording of a secret key
is a live credential sitting in someone's cloud storage.

Three ways to handle it, best first:

1. **The client pastes it themselves.** Give them control of the cPanel window,
   or send them the file path and let them do it while you pause the share.
2. **Pause the share** for the ten seconds it takes to paste, then resume.
3. **Accept it and rotate straight after.** If it does end up on screen, have
   the client regenerate the key in Paystack the moment the demo ends. A
   rotated key is harmless; an exposed one is not.

The **public key** and the **webhook URL** are both safe to show. Only the
secret key needs this care.

---

## 1. The client opens Paystack

Dashboard, then check the **Live / Test toggle** at the top is set to **Live**.

Everything below is per mode. Live and Test have separate keys and separate
webhook fields, and mixing them is the single most common setup mistake.

## 2. Settings, then API Keys and Webhooks

## 3. Set the webhook URL first

It is the safe one, so do it while the screen is still shared.

Paste into **Webhook URL**:

```
https://okveggies.com.ng/api/v1/paystack_webhook.php
```

Save.

This is what tells OK Veggies a payment succeeded even if the customer closes
the tab. Without it, a payment still confirms when the customer returns to the
site, but an abandoned tab waits for the reconciliation sweep instead of
settling at once.

## 4. Copy the keys

The **Public Key** is visible. The **Secret Key** is hidden behind a reveal or
copy control.

Use the copy control rather than selecting the text by hand. A manual selection
very often clips a character, and a clipped key is rejected exactly like a wrong
one.

**This is the moment to apply the screen-share care above.**

## 5. Put them in .env

cPanel, File Manager, the `.env` at the application root. Replace the Paystack
block with:

```
PAYSTACK_SECRET_KEY=sk_live_the_real_secret_key
PAYSTACK_PUBLIC_KEY=pk_live_the_real_public_key
```

Delete `PAYSTACK_WEBHOOK_SECRET` if it is still there. No code reads it, and
Paystack has no such value: webhooks are signed with the secret key itself.
Leaving it invites someone debugging a signature failure to change the one
setting that has no effect.

Save. No restart is needed; the next request picks it up.

## 6. Confirm before demonstrating anything

Open:

```
https://okveggies.com.ng/public/healthcheck.php?token=YOUR_MIGRATE_TOKEN
```

Every line must read `ok`. In particular:

```
ok    secret key is a real key, not the template placeholder
ok    Paystack accepts our key  (authenticated ...)
```

and at the foot:

```
LIVE MODE. Payments made here move real money.
```

**Do not place the demo order until that page is clean.** It takes five seconds
and it is the difference between a smooth demo and a failure in front of the
client.

### If "Paystack accepts our key" still fails

The page prints a fingerprint:

```
note  key fingerprint, compare with your Paystack dashboard
      (sk_live_... 48 characters, ending a1b2)
```

Compare the character count and the last four with what the dashboard shows.

- **Wrong length** means the paste was clipped. Copy it again with the copy
  control.
- **Right length, still 401** means the key is stale. Have the client
  regenerate it and use the fresh one.
- **Ends up right** and the check goes green.

---

## 7. The demo

1. Shop, open a product
2. Set the quantity, add to basket
3. Checkout, fill the details, pick a delivery day
4. **Pay the full amount**, then Place order
5. Paystack's own page opens. Pay by card, transfer or USSD
6. Back on the order page, marked paid
7. Show the client the payment on **their** Paystack dashboard
8. Show `/admin/payments.php`: the transaction, the fee, and the reconciliation

Keep it small. An existing order like OKV26001 at ₦810 proves everything a
₦50,000 one would.

## 8. If something needs undoing

`/admin/payments.php` issues a full or partial refund, Owner only, with a
confirmation that names the order, the customer, what was paid and what is left
before anything is sent.

---

## After the call

If the secret key was visible on screen at any point, have the client rotate it
in Paystack and update `.env` with the new one. It costs a minute and closes the
exposure completely.
