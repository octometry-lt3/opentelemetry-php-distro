#!/usr/bin/env bash
set -e -o pipefail

repo_root_dir="$(realpath "$(dirname "${BASH_SOURCE[0]}")/../..")"
test_parent_dir="${repo_root_dir}/_BUILT"
if [ ! -d "${test_parent_dir}" ]; then
    echo "Test parent directory does not exist: ${test_parent_dir}" >&2
    exit 1
fi
test_dir="$(mktemp -d "${test_parent_dir}/test_build_native_github_sha.XXXXXX")"
docker_stub_dir="${test_dir}/bin"
docker_capture_file="${test_dir}/docker-args"

cleanup() {
    rm -rf "${test_dir}"
}
trap cleanup EXIT

mkdir -p "${docker_stub_dir}"
cat >"${docker_stub_dir}/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$@" >"${DOCKER_CAPTURE_FILE}"
STUB
chmod +x "${docker_stub_dir}/docker"

run_build() {
    env -u CONAN_CACHE_PATH "$@" \
        PATH="${docker_stub_dir}:${PATH}" \
        DOCKER_CAPTURE_FILE="${docker_capture_file}" \
        "${repo_root_dir}/tools/build/build_native.sh" \
        --build_architecture linux-x86-64 --skip_configure --skip_unit_tests >/dev/null
}

assert_github_sha_arg() {
    local expected="$1"
    local found=false
    while IFS= read -r argument; do
        if [ "${argument}" = "${expected}" ]; then
            found=true
        fi
    done <"${docker_capture_file}"

    if [ "${found}" != true ]; then
        echo "Expected Docker argument not found: ${expected}" >&2
        return 1
    fi
}

assert_no_github_sha_arg() {
    local argument
    while IFS= read -r argument; do
        if [[ "${argument}" == GITHUB_SHA=* || "${argument}" = GITHUB_SHA ]]; then
            echo "Unexpected Docker GITHUB_SHA argument: ${argument}" >&2
            return 1
        fi
    done <"${docker_capture_file}"
}

run_build -u GITHUB_SHA
assert_no_github_sha_arg

run_build GITHUB_SHA=
assert_github_sha_arg GITHUB_SHA=

run_build GITHUB_SHA=abc123
assert_github_sha_arg GITHUB_SHA=abc123

echo "build_native.sh GITHUB_SHA Docker argument regression passed"
