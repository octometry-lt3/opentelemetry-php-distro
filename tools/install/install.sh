#!/usr/bin/env bash
set -euo pipefail

readonly DEFAULT_VERSION='0.7.0'
readonly GRPC_VERSION='1.66.0'
readonly RELEASES_URL='https://github.com/open-telemetry/opentelemetry-php-distro/releases/download'

usage() {
    cat <<'EOF'
Usage: install.sh [--version <semver>] [--help]

Install the OpenTelemetry PHP Distro on Ubuntu 22.04 amd64.
The default release version is 0.7.0.
EOF
}

die() {
    echo "Error: $*" >&2
    exit 1
}

version="${DEFAULT_VERSION}"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            [[ $# -ge 2 ]] || die '--version requires a value'
            version="$2"
            shift 2
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            die "unknown argument: $1"
            ;;
    esac
done

if [[ ! "${version}" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    die "version must be a semantic version such as 0.7.0: ${version}"
fi

if [[ "${EUID}" -ne 0 ]]; then
    command -v sudo >/dev/null 2>&1 || die 'must run as root or have sudo available'
    exec sudo --preserve-env=OTEL_PHP_INSTALL_TEST_RELEASE_BASE_URL bash "$0" "$@"
fi

[[ -r /etc/os-release ]] || die 'cannot identify the operating system'
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == 'ubuntu' && "${VERSION_ID:-}" == '22.04' ]] \
    || die 'this installer supports Ubuntu 22.04 only'

command -v dpkg >/dev/null 2>&1 || die 'dpkg is required'
[[ "$(dpkg --print-architecture)" == 'amd64' ]] \
    || die 'this installer supports amd64 only'

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install --yes --no-install-recommends \
    autoconf \
    build-essential \
    ca-certificates \
    curl \
    libssl-dev \
    pkg-config \
    php8.1-cli \
    php8.1-dev \
    php-pear \
    zlib1g-dev

if ! php8.1 -r 'exit(extension_loaded("grpc") ? 0 : 1);'; then
    printf '\n' | pecl install "grpc-${GRPC_VERSION}"
fi

mkdir -p /etc/php/8.1/mods-available
printf '%s\n' 'extension=grpc.so' >/etc/php/8.1/mods-available/grpc.ini
phpenmod -v 8.1 grpc
php8.1 -r 'extension_loaded("grpc") || exit(1);'

tmp_dir=$(mktemp -d)
cleanup() {
    rm -rf "${tmp_dir}"
}
trap cleanup EXIT

asset_name="opentelemetry-php-distro_${version}_amd64.deb"
release_base_url="${RELEASES_URL}/v${version}"
# This override is only for the Docker fixture test; production uses GitHub Releases.
if [[ -n "${OTEL_PHP_INSTALL_TEST_RELEASE_BASE_URL:-}" ]]; then
    release_base_url="${OTEL_PHP_INSTALL_TEST_RELEASE_BASE_URL}"
fi

deb_path="${tmp_dir}/${asset_name}"
checksum_path="${tmp_dir}/${asset_name}.sha512"
curl -fsSL "${release_base_url}/${asset_name}" -o "${deb_path}"
curl -fsSL "${release_base_url}/${asset_name}.sha512" -o "${checksum_path}"

(cd "${tmp_dir}" && sha512sum --check "${checksum_path}")
(cd "${tmp_dir}" && apt-get install --yes "./${asset_name}")

echo "Installed OpenTelemetry PHP Distro ${version} on Ubuntu 22.04 amd64."
