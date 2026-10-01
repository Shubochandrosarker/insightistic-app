# Social Login — Google, Microsoft & GitHub

Insightistic supports "Continue with Google / Microsoft / GitHub" on the login
and register pages. It is **opt-in**: a button only appears once that provider's
credentials are configured on the API. No credentials = no button, and the rest
of auth (email + password) keeps working untouched.

## How it works

```
Browser → GET /api/auth/oauth/{provider}/redirect   → 302 to provider
Provider → GET /api/auth/oauth/{provider}/callback   → app finds/creates the user,
           bootstraps an org for new accounts, mints a Sanctum token, and
           302s the browser to  /auth/callback#token=…  (token in the URL
           fragment, never sent to a server / never logged).
```

New social users get the same 14-day trial + owner organization as a normal
signup. If a social email matches an existing account, the social identity is
linked to it.

## 1. Pick your redirect base

By default the callback lives on the **app** domain (proxy-friendly):

```
https://app.insightistic.com/api/auth/oauth/<provider>/callback
```

That works in both proxy mode and direct mode. If you run the API on its own
public host and prefer callbacks there, set `OAUTH_REDIRECT_BASE=https://api.insightistic.com`
(or override a single provider with `GOOGLE_REDIRECT_URI`, etc.).

Use the **exact** callback URL below in each provider's console.

## 2. Google

1. <https://console.cloud.google.com/> → APIs & Services → **Credentials**.
2. **Create Credentials → OAuth client ID → Web application**.
3. Authorized redirect URI:
   `https://app.insightistic.com/api/auth/oauth/google/callback`
4. Copy the client ID/secret into the API env:

```env
GOOGLE_CLIENT_ID=…
GOOGLE_CLIENT_SECRET=…
```

## 3. Microsoft

1. <https://portal.azure.com/> → **Microsoft Entra ID → App registrations → New registration**.
2. Supported account types: *Accounts in any org directory and personal Microsoft accounts* (matches `MICROSOFT_TENANT=common`).
3. Redirect URI (Web):
   `https://app.insightistic.com/api/auth/oauth/microsoft/callback`
4. **Certificates & secrets → New client secret**. Then:

```env
MICROSOFT_CLIENT_ID=…
MICROSOFT_CLIENT_SECRET=…
MICROSOFT_TENANT=common
```

> The Microsoft provider uses the `socialiteproviders/microsoft` package, already
> added to the API's Composer requirements and wired in `AppServiceProvider`.

## 4. GitHub

1. <https://github.com/settings/developers> → **OAuth Apps → New OAuth App**.
2. Authorization callback URL:
   `https://app.insightistic.com/api/auth/oauth/github/callback`
3. Generate a client secret, then:

```env
GITHUB_CLIENT_ID=…
GITHUB_CLIENT_SECRET=…
```

## 5. Apply

- **Docker:** add the vars to your Docker Manager env panel (they are already
  passed through in `docker-compose.hostinger.yml`) and redeploy the `api`.
- **PM2/manual:** add them to `insightistic-api/.env` and run
  `php artisan config:clear`.

Reload `https://app.insightistic.com/login` — the configured buttons appear.
Verify which providers the API considers enabled:

```bash
curl https://app.insightistic.com/api/auth/oauth/providers
# {"providers":["google","github"]}
```

## Notes

- The frontend reads `GET /api/auth/oauth/providers` to decide which buttons to
  render, so you never see a button that would 404.
- Tokens are personal-access (Sanctum) tokens, identical to password login — the
  dashboard, roles and billing all behave the same for social accounts.
- To restrict the API's CORS in direct mode, also set
  `CORS_ALLOWED_ORIGINS=https://app.insightistic.com`.
