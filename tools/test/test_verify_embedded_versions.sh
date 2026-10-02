#!/usr/bin/env bash
set -euo pipefail

repo_root_dir="$(realpath "$(dirname "${BASH_SOURCE[0]}")/../..")"
test_dir="$(mktemp -d "${TMPDIR:-/tmp}/test_verify_embedded_versions.XXXXXX")"
trap 'rm -rf "${test_dir}"' EXIT

report_file="${test_dir}/php-ri.txt"
run_verifier() {
    "${repo_root_dir}/packaging/test/verify_embedded_versions.sh" "${report_file}"
}

assert_failure() {
    local expected="$1"
    local output
    if output="$(run_verifier 2>&1)"; then
        echo "Expected verifier failure: ${expected}" >&2
        exit 1
    fi
    if [[ "${output}" != *"${expected}"* ]]; then
        echo "Verifier failure did not contain ${expected}: ${output}" >&2
        exit 1
    fi
}

cat >"${report_file}" <<'EOF'
opentelemetry_distro
Native part version => 0.7.0~abc123
PHP part version => 0.7.0~abc123
EOF
run_verifier >/dev/null

sed -i 's/PHP part version =>.*/PHP part version => 0.7.0~def456/' "${report_file}"
assert_failure "native=0.7.0~abc123, PHP=0.7.0~def456"

sed -i 's/PHP part version =>.*/PHP part version => 0.7.0~abc123-dirty/' "${report_file}"
assert_failure "dirty"

sed -i '/PHP part version/d' "${report_file}"
assert_failure "missing or unparseable"

echo "verify_embedded_versions.sh fixture tests passed"
