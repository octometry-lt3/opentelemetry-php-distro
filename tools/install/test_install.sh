#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: tools/install/test_install.sh --deb <path> [--version <semver>]

Runs the Ubuntu 22.04 amd64 installer test in Docker. The DEB is exposed as
a local release-shaped fixture; no GitHub release is modified.
EOF
}

version='0.7.0'
deb_path=''

while [[ $# -gt 0 ]]; do
    case "$1" in
        --deb)
            [[ $# -ge 2 ]] || { echo '--deb requires a path' >&2; exit 1; }
            deb_path="$2"
            shift 2
            ;;
        --version)
            [[ $# -ge 2 ]] || { echo '--version requires a value' >&2; exit 1; }
            version="$2"
            shift 2
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -z "${deb_path}" || ! -f "${deb_path}" ]]; then
    echo 'A readable --deb path is required' >&2
    exit 1
fi
if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Invalid semver: ${version}" >&2
    exit 1
fi
command -v docker >/dev/null || { echo 'docker is required' >&2; exit 1; }

script_dir=$(realpath "$(dirname "${BASH_SOURCE[0]}")")
fixture_dir=$(mktemp -d)
trap 'rm -rf "${fixture_dir}" "${bad_fixture_dir:-}" "${fake_os_release:-}"' EXIT

asset_name="opentelemetry-php-distro_${version}_amd64.deb"
cp "${deb_path}" "${fixture_dir}/${asset_name}"
(cd "${fixture_dir}" && sha512sum "${asset_name}" >"${asset_name}.sha512")

docker run --rm --platform linux/amd64 \
    -e "OTEL_PHP_INSTALL_TEST_RELEASE_BASE_URL=file:///release-fixture" \
    -v "${script_dir}/install.sh:/installer/install.sh:ro" \
    -v "${fixture_dir}:/release-fixture:ro" \
    ubuntu:22.04 \
    bash -s -- "${version}" <<'CONTAINER_SCRIPT'
set -euo pipefail
version="$1"

bash /installer/install.sh --version "${version}"

test "$(dpkg-query --showformat='${Status}' --show opentelemetry-php-distro 2>/dev/null)" = 'install ok installed'
test "$(php -r "var_export(extension_loaded('grpc'));" 2>/dev/null)" = 'true'
test "$(php -r "var_export(extension_loaded('opentelemetry_distro'));" 2>/dev/null)" = 'true'
CONTAINER_SCRIPT

run_rejection_test() {
    local image="$1"
    shift
    if docker run --rm --platform linux/amd64 \
        -v "${script_dir}/install.sh:/installer/install.sh:ro" \
        "${image}" bash /installer/install.sh "$@" >/dev/null 2>&1; then
        echo "Expected installer rejection for ${image} $*" >&2
        exit 1
    fi
}

run_rejection_test ubuntu:22.04 --version '0.7.0;touch /tmp/unsafe'

fake_os_release=$(mktemp)
printf '%s\n' 'ID=debian' 'VERSION_ID="12"' >"${fake_os_release}"
if docker run --rm --platform linux/amd64 \
    -v "${script_dir}/install.sh:/installer/install.sh:ro" \
    -v "${fake_os_release}:/etc/os-release:ro" \
    ubuntu:22.04 bash /installer/install.sh --version "${version}" >/dev/null 2>&1; then
    echo 'Expected installer rejection for a non-Ubuntu host' >&2
    exit 1
fi

if docker run --rm --platform linux/arm64 \
    -v "${script_dir}/install.sh:/installer/install.sh:ro" \
    ubuntu:22.04 bash /installer/install.sh --version "${version}" >/dev/null 2>&1; then
    echo 'Expected installer rejection for a non-amd64 host' >&2
    exit 1
fi

bad_fixture_dir=$(mktemp -d)
trap 'rm -rf "${fixture_dir}" "${bad_fixture_dir}"' EXIT
cp "${fixture_dir}/${asset_name}" "${bad_fixture_dir}/${asset_name}"
printf '%s  %s\n' '0bad' "${asset_name}" >"${bad_fixture_dir}/${asset_name}.sha512"

if docker run --rm --platform linux/amd64 \
    -e 'OTEL_PHP_INSTALL_TEST_RELEASE_BASE_URL=file:///release-fixture' \
    -v "${script_dir}/install.sh:/installer/install.sh:ro" \
    -v "${bad_fixture_dir}:/release-fixture:ro" \
    ubuntu:22.04 bash /installer/install.sh --version "${version}" >/dev/null 2>&1; then
    echo 'Expected installer rejection for an invalid DEB checksum' >&2
    exit 1
fi

echo 'Installer validation passed.'
