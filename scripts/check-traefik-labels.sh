#!/usr/bin/env bash
# Post-deploy guard: only the services that really serve HTTP may carry an
# effective traefik.enable=true. Effective = image labels merged with
# container labels, which is exactly what Traefik's docker provider reads.
#
# Usage: scripts/check-traefik-labels.sh [compose-project] [allowed services...]
#   scripts/check-traefik-labels.sh insightistic-app api app
set -euo pipefail
project="${1:-insightistic-app}"
[ "$#" -gt 0 ] && shift
if [ "$#" -gt 0 ]; then allowed=("$@"); else allowed=(api app); fi

fail=0
for id in $(docker ps -q --filter "label=com.docker.compose.project=${project}"); do
  svc=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$id")
  name=$(docker inspect -f '{{.Name}}' "$id" | sed 's#^/##')
  enabled=$(docker inspect -f '{{index .Config.Labels "traefik.enable"}}' "$id")
  routers=$(docker inspect -f '{{range $k,$v := .Config.Labels}}{{$k}}{{"\n"}}{{end}}' "$id" | grep '^traefik.http.routers' | cut -d. -f4 | sort -u | tr '\n' ' ' || true)
  ok_svc=0; for a in "${allowed[@]}"; do [ "$svc" = "$a" ] && ok_svc=1; done
  if [ "$enabled" = "true" ] && [ "$ok_svc" -ne 1 ]; then
    echo "FAIL $name (service=$svc) has traefik.enable=true; routers: ${routers:-none}"
    fail=1
  else
    echo "ok   $name (service=$svc) traefik.enable=${enabled:-unset}"
  fi
done
exit $fail
