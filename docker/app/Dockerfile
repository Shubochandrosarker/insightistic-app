FROM node:20-alpine AS deps

WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1

COPY insightistic-app/package.json insightistic-app/package-lock.json ./
RUN npm ci

COPY insightistic-app/ ./
ARG NEXT_PUBLIC_API_URL=https://api.insightistic.com
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
RUN npm run build && npm prune --omit=dev

FROM node:20-alpine AS runner

WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /app/package.json ./package.json
COPY --from=deps /app/node_modules ./node_modules
COPY --from=deps /app/.next ./.next
COPY --from=deps /app/next.config.mjs ./next.config.mjs
COPY --from=deps /app/public ./public

EXPOSE 3000
CMD ["npm", "start"]
