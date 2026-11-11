FROM node:26-alpine AS deps

WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1

COPY insightistic-app/package.json insightistic-app/package-lock.json ./
RUN npm ci

COPY insightistic-app/ ./
# Empty by default => proxy mode (browser calls same-origin /api, forwarded by
# Next.js to API_PROXY_TARGET at runtime). Set to an API URL for direct mode.
ARG NEXT_PUBLIC_API_URL=
ARG API_PROXY_TARGET=http://api:8000
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
ENV API_PROXY_TARGET=${API_PROXY_TARGET}
RUN npm run build && npm prune --omit=dev

FROM node:26-alpine AS runner

WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV API_PROXY_TARGET=http://api:8000

COPY --from=deps /app/package.json ./package.json
COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/.next ./.next
COPY --from=deps /app/next.config.mjs ./next.config.mjs
COPY --from=deps /app/public ./public

# Images never route themselves. Traefik routing is opt-in per *service* in
# compose (traefik.enable=true on the web service only). Without this default,
# an image that inherits router labels (e.g. one made with `docker commit` from
# the routed api container) makes queue/scheduler join the same Traefik
# service and ~2/3 of api.insightistic.com requests 502 (incident 2026-11-07).
LABEL traefik.enable="false"

EXPOSE 3000
CMD ["npm", "start"]
