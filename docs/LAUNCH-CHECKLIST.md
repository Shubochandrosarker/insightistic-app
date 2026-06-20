# Insightistic — Launch Checklist

## Infra / domains
- [ ] DNS: `insightistic.com` (marketing), `app.` (frontend), `api.` (Laravel)
- [ ] SSL on all three (Cloudflare proxied)
- [ ] API at domain root; `APP_URL` + `INSIGHTISTIC_APP_URL` set correctly
- [ ] Postgres provisioned; `php artisan migrate` run; `PlanSeeder` run
- [ ] Queue worker running (`php artisan queue:work`) + cron `schedule:run`
- [ ] `storage:link` done; reports disk writable

## Billing
- [ ] Stripe products/prices created; price IDs saved on `plans` rows
- [ ] `STRIPE_SECRET` + `STRIPE_WEBHOOK_SECRET` set
- [ ] Webhook endpoint `https://api.insightistic.com/api/billing/webhook/stripe` registered
- [ ] Test: checkout → active subscription → portal → cancel reflects in dashboard

## Email
- [ ] `MAIL_*` configured (SES/SendGrid/SMTP); from-address verified
- [ ] Welcome email on register, invite email, password reset all deliver

## Product
- [ ] Connector plugin zipped; install → connect → first sync verified on a real store
- [ ] Snapshots build nightly; Overview/Revenue/Products/Customers populated
- [ ] AI insights generate (rule-based with no key; OpenAI if key set)
- [ ] Reports generate (PDF + HTML) and send by email; white-label logo renders

## Legal / trust
- [ ] Terms + Privacy replaced with reviewed copy
- [ ] Refund/cancellation policy stated
- [ ] Data deletion path documented

## Go-to-market (spec §22)
- [ ] Pricing page live; trial (14 days, no card) works end-to-end
- [ ] First paid offer / LTD ready (e.g. founding-agency deal)
- [ ] Demo account seeded for sales calls (`demo@insightistic.com / demo12345`)
- [ ] Support inbox + basic docs linked from app
