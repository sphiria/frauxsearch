#!/usr/bin/env bash
set -euo pipefail

if [[ -n "${FRAUXSEARCH_RANKING_API_URL:-}" ]]; then
	exec php "$(dirname -- "${BASH_SOURCE[0]}")/checkRanking.php" "$FRAUXSEARCH_RANKING_API_URL"
fi

context="${KUBE_CONTEXT:-minikube}"
namespace="${KUBE_NAMESPACE:-default}"
deployment="${MEDIAWIKI_DEPLOYMENT:-yuisis}"
container="${MEDIAWIKI_CONTAINER:-yuisis}"

kubectl --context "$context" -n "$namespace" exec "deployment/$deployment" -c "$container" -- \
	php84 extensions/FrauxSearch/tests/integration/checkRanking.php \
	'http://127.0.0.1:8080/api.php'
