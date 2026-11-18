#!/usr/bin/env bash

set -euo pipefail

vendor_dir="${1:?Usage: $0 <vendor-dir>}"
installed_json="${vendor_dir}/composer/installed.json"
roots_file="${vendor_dir}/grpc/grpc/etc/roots.pem"

expected_grpc_version="1.82.0"
expected_vulnerable_sha256="96143344c4ae109588125b2c41d5daf654a5fafc6e23fac37f7ba3d29da7e776"
fixed_sha256="e3ada9517680b712e979331df3c34d805ebcdcbb14da13dcce004f4171e4b8ee"
fixed_roots_url="https://raw.githubusercontent.com/grpc/grpc/8b32af3bf498c2ba9667410eaa8c836e700e97e6/etc/roots.pem"

grpc_version="$(php -r '
    $installed = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    foreach ($installed["packages"] as $package) {
        if ($package["name"] === "grpc/grpc") {
            echo $package["version"];
            exit(0);
        }
    }
    fwrite(STDERR, "grpc/grpc is not installed\n");
    exit(1);
' "${installed_json}")"

if [[ "${grpc_version}" != "${expected_grpc_version}" ]]; then
    echo "Refusing to backport gRPC roots: expected ${expected_grpc_version}, found ${grpc_version}" >&2
    exit 1
fi

current_sha256="$(sha256sum "${roots_file}" | cut -d ' ' -f 1)"
if [[ "${current_sha256}" != "${expected_vulnerable_sha256}" ]]; then
    echo "Refusing to replace unexpected gRPC roots.pem: ${current_sha256}" >&2
    exit 1
fi

work_dir="$(mktemp -d)"
trap 'rm -rf "${work_dir}"' EXIT
fixed_roots_file="${work_dir}/roots.pem"

curl -fLsS "${fixed_roots_url}" -o "${fixed_roots_file}"
echo "${fixed_sha256}  ${fixed_roots_file}" | sha256sum -c -
cp "${fixed_roots_file}" "${roots_file}"
chmod 0644 "${roots_file}"
echo "${fixed_sha256}  ${roots_file}" | sha256sum -c -

echo "Backported the official gRPC roots.pem security fix for PHP 8.1 (${grpc_version})."
