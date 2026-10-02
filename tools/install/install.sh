#!/usr/bin/env bash
set -euo pipefail

readonly DEFAULT_VERSION='0.7.1'
readonly RELEASES_URL='https://github.com/open-telemetry/opentelemetry-php-distro/releases/download'
readonly PPA_KEY_FINGERPRINT='14AA40EC0831756756D7F66C4F4EA0AAE5267A6C'
readonly PPA_KEY_URL='https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C'
readonly PPA_KEYRING='/usr/share/keyrings/ppa_ondrej_php.gpg'
readonly PPA_SOURCE='/etc/apt/sources.list.d/ppa_ondrej_php.list'

usage() {
    cat <<'EOF'
Usage: install.sh [--version <semver>] [--help]

Install the OpenTelemetry PHP Distro on Ubuntu 22.04 amd64.
The default release version is 0.7.1.
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
    die "version must be a semantic version such as 0.7.1: ${version}"
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
    ca-certificates \
    curl \
    gnupg

key_dir=$(mktemp -d)
tmp_dir=''
cleanup() {
    rm -rf "${key_dir}"
    [[ -z "${tmp_dir}" ]] || rm -rf "${tmp_dir}"
}
trap cleanup EXIT

curl -fsSL "${PPA_KEY_URL}" -o "${key_dir}/ppa.key"
gpg --batch --dearmor <"${key_dir}/ppa.key" >"${key_dir}/ppa.gpg"
key_fingerprint=$(gpg --show-keys --with-colons "${key_dir}/ppa.gpg" \
    | awk -F: '$1 == "fpr" {print toupper($10); exit}')
[[ "${key_fingerprint}" == "${PPA_KEY_FINGERPRINT}" ]] \
    || die "unexpected PPA signing key fingerprint: ${key_fingerprint:-missing}"
install -D -m 0644 "${key_dir}/ppa.gpg" "${PPA_KEYRING}"
printf '%s\n' \
    'deb [signed-by=/usr/share/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main' \
    >"${PPA_SOURCE}"

apt-get update
apt-get install --yes --no-install-recommends php8.1-cli php8.1-grpc

phpenmod -v 8.1 grpc
if ! php8.1 --ri grpc >/dev/null; then
    echo 'PHP 8.1 gRPC extension failed to load.' >&2
    dpkg-query --showformat='Installed php8.1-grpc version: ${Version}\n' --show php8.1-grpc >&2 || true
    php8.1 --ri grpc >&2 || true
    die 'php8.1 --ri grpc failed'
fi
php8.1 -r 'extension_loaded("grpc") || exit(1);' \
    || {
        dpkg-query --showformat='Installed php8.1-grpc version: ${Version}\n' --show php8.1-grpc >&2 || true
        die 'PHP 8.1 CLI does not load the gRPC extension'
    }

tmp_dir=$(mktemp -d)

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
