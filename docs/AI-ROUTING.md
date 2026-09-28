# Insightistic AI routing

The API selects a provider chain from the organization's effective billing
entitlement on the server. The browser cannot request a higher model tier.

| Tier | Provider order |
| --- | --- |
| Free and trial | WPistic AI gateway → OpenRouter `openrouter/free` → deterministic rule fallback |
| Paid | OpenRouter `openai/gpt-6-luna` → `z-ai/glm-5.3-flash` model fallback → WPistic AI gateway → deterministic rule fallback |

The explicit free-model retry list includes NVIDIA Nemotron 3 Super, Google
Gemma 4 31B, Gemma 4 26B A4B, and GLM 5.3 Flash `:free`. Keep `:free` on the
free-tier GLM ID; the paid GLM fallback omits that suffix and incurs the normal
OpenRouter model charge.

An organization with external AI processing disabled uses only the local
rule provider. Trial accounts use the free model chain unless a Super Admin
has explicitly granted a paid override. The WPistic gateway receives a tier
derived from the same effective entitlement.

Configure `OPENROUTER_API_KEY` on the API, queue, and scheduler containers.
The key must stay server-side; do not add it to any `NEXT_PUBLIC_*` variable
or frontend environment. `OPENROUTER_FREE_MODEL`,
`OPENROUTER_FREE_FALLBACK_MODELS`, `OPENROUTER_PAID_MODEL`, and
`OPENROUTER_PAID_FALLBACK_MODELS` control model IDs. Configure the WPistic
gateway with `WPISTIC_GATEWAY_URL=https://ai-api.wpistic.com`,
`WPISTIC_GATEWAY_CHAT_PATH=/compat/chat/completions`, and the server-side
`WPISTIC_GATEWAY_PRODUCT_KEY`; the client also accepts a complete chat endpoint
as `WPISTIC_GATEWAY_URL`. For Docker Compose, store secrets in a mode-600 env
file that is excluded from source control and restart the API, queue, and
scheduler after changes so Laravel reloads its cached configuration.

OpenRouter model fallback is attempted before the application moves to the
next provider. Copilot retries another provider only before it has emitted
visible response text, preventing duplicate partial answers. Provider
telemetry and Copilot run records retain the model that actually answered.

Automated coverage lives in `tests/Feature/AiProviderRoutingTest.php` and
`tests/Unit/OpenRouterProviderTest.php`.
