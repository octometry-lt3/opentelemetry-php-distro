#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "Usage: $0 <php-ri-report>" >&2
    exit 1
fi

report_file="$1"
if [[ ! -f "${report_file}" ]]; then
    echo "Installed version verification failed: report is absent: ${report_file}" >&2
    exit 1
fi

echo "php --ri opentelemetry_distro output:"
cat "${report_file}"

native_version="$(sed -nE 's/^Native part version[[:space:]]*=>[[:space:]]*(.*)$/\1/p' "${report_file}")"
php_version="$(sed -nE 's/^PHP part version[[:space:]]*=>[[:space:]]*(.*)$/\1/p' "${report_file}")"

if [[ -z "${native_version}" || -z "${php_version}" ]]; then
    echo "Installed version verification failed: native or PHP part version line is missing or unparseable" >&2
    exit 1
fi
if [[ "${native_version}" == *-dirty || "${php_version}" == *-dirty ]]; then
    echo "Installed version verification failed: a version is dirty: native=${native_version}, PHP=${php_version}" >&2
    exit 1
fi
if [[ "${native_version}" != "${php_version}" ]]; then
    echo "Installed version verification failed: native=${native_version}, PHP=${php_version}" >&2
    exit 1
fi

echo "Installed native and PHP part versions match: ${native_version}"
