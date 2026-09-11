#!/usr/bin/env bash
set -euo pipefail

context="${KUBE_CONTEXT:-minikube}"
namespace="${KUBE_NAMESPACE:-default}"
deployment="${MEDIAWIKI_DEPLOYMENT:-yuisis}"
wiki_container="${MEDIAWIKI_CONTAINER:-yuisis}"
test_pod="${FRAUXSEARCH_TEST_POD:?Set FRAUXSEARCH_TEST_POD to the prepared local command-only wiki/private-Redis Pod}"
test_container="${FRAUXSEARCH_TEST_CONTAINER:-maintenance}"
interwiki_prefix="${FRAUXSEARCH_TEST_INTERWIKI_PREFIX:?Set FRAUXSEARCH_TEST_INTERWIKI_PREFIX to a verified existing external prefix}"
base_conf="${FRAUXSEARCH_TEST_BASE_CONF:-/var/www/html/LocalSettings.php}"
run="${FRAUXSEARCH_TEST_RUN:-$(python3 -c 'import secrets; print(secrets.token_hex(6))')}"

if [[ ! "$run" =~ ^[a-f0-9]{12}$ || "$base_conf" != /* ]]; then
    printf 'Use a fresh 12-hex run token and an absolute test base configuration path.\n' >&2
    exit 1
fi

kube=(kubectl --context "$context" -n "$namespace")
exec_test=("${kube[@]}" exec "$test_pod" -c "$test_container" --)

"${kube[@]}" rollout status "deployment/$deployment" --timeout=30s
expected_image="$("${kube[@]}" get deployment "$deployment" -o json | python3 -c '
import json, sys
containers = json.load(sys.stdin)["spec"]["template"]["spec"]["containers"]
selected = [item for item in containers if item["name"] == sys.argv[1]]
if len(selected) != 1:
    raise SystemExit("The requested wiki container does not exist in the deployment.")
print(selected[0]["image"])
' "$wiki_container")"

"${kube[@]}" get pod "$test_pod" -o json | python3 -c '
import json, sys
pod = json.load(sys.stdin)
spec = pod["spec"]
containers = {item["name"]: item for item in spec["containers"]}
wiki = containers.get(sys.argv[1], {})
redis = containers.get("redis", {})
command = wiki.get("command", []) + wiki.get("args", [])
redis_command = redis.get("command", []) + redis.get("args", [])
def option(name):
    values = []
    for i, value in enumerate(redis_command):
        if value == name and i + 1 < len(redis_command):
            values.append(redis_command[i + 1])
        elif value.startswith(name + "="):
            values.append(value[len(name) + 1:])
    return values
ready = any(item["type"] == "Ready" and item["status"] == "True"
            for item in pod.get("status", {}).get("conditions", []))
idle = (len(command) == 2 and command[0] in ("sleep", "/bin/sleep")
        and (command[1] == "infinity" or command[1].isdigit()))
if (pod.get("status", {}).get("phase") != "Running" or not ready
        or spec.get("hostNetwork", False) or not idle
        or set(containers) != {sys.argv[1], "redis"}
        or wiki.get("image") != sys.argv[2]
        or any("persistentVolumeClaim" in volume or "hostPath" in volume
               for volume in spec.get("volumes", []))
        or option("--port") != ["6389"] or option("--bind") != ["127.0.0.1"]):
    raise SystemExit("Test Pod must be Ready, command-only, on the deployment image, with private loopback Redis6389 and no PVC/hostPath.")
' "$test_container" "$expected_image"

"${exec_test[@]}" php84 -r '
if (!is_file($argv[1])) { throw new RuntimeException("Test base configuration is missing."); }
$r = new Redis();
if (!$r->connect("127.0.0.1", 6389, 2)
    || !$r->setOption(Redis::OPT_MAX_RETRIES, 0)
    || !$r->setOption(Redis::OPT_READ_TIMEOUT, 2)
    || !$r->select(0) || $r->dbSize() !== 0) {
    throw new RuntimeException("Private lifecycle Redis must be reachable and empty; preserve any retained run.");
}
' "$base_conf"

printf 'Integration context=%s namespace=%s deployment=%s test_pod=%s lifecycle_run=%s\n' \
    "$context" "$namespace" "$deployment" "$test_pod" "$run"

"${exec_test[@]}" php84 extensions/FrauxSearch/tests/integration/searchBehavior.php \
    --conf "$base_conf" --execute
"${exec_test[@]}" php84 extensions/FrauxSearch/tests/integration/coordination.php \
    --conf "$base_conf" --execute
"${exec_test[@]}" env \
    "FRAUXSEARCH_TEST_BASE_CONF=$base_conf" "FRAUXSEARCH_TEST_RUN=$run" \
    FRAUXSEARCH_TEST_REDIS_SERVER=127.0.0.1:6389 \
    php84 extensions/FrauxSearch/tests/integration/lifecycle.php \
    --conf /var/www/html/extensions/FrauxSearch/tests/integration/lifecycleConf.php \
    --interwiki-prefix "$interwiki_prefix" --execute

KUBE_CONTEXT="$context" KUBE_NAMESPACE="$namespace" MEDIAWIKI_DEPLOYMENT="$deployment" \
    MEDIAWIKI_CONTAINER="$wiki_container" bash "$(dirname "$0")/ranking.sh"
printf 'FrauxSearch search behavior, coordination, lifecycle, and ranking integration checks passed.\n'
