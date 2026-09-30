#!/usr/bin/env bash
set -e -u -o pipefail

show_help() {
    echo "Usage: $0 --build_architecture <architecture> [--php-code-dir <directory>] [--native-build-dir <directory>]"
}

PHP_CODE_DIR="_BUILT/php_code_for_packages"
NATIVE_BUILD_DIR="prod/native/_build"

while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --build_architecture)
            BUILD_ARCHITECTURE="${2:?Missing value for --build_architecture}"
            shift
            ;;
        --php-code-dir)
            PHP_CODE_DIR="${2:?Missing value for --php-code-dir}"
            shift
            ;;
        --native-build-dir)
            NATIVE_BUILD_DIR="${2:?Missing value for --native-build-dir}"
            shift
            ;;
        --help)
            show_help
            exit 0
            ;;
        *)
            echo "Unknown parameter passed: $1" >&2
            show_help >&2
            exit 1
            ;;
    esac
    shift
done

if [[ -z "${BUILD_ARCHITECTURE+x}" ]]; then
    echo "Error: Missing required argument --build_architecture" >&2
    show_help >&2
    exit 1
fi

fail() {
    echo "Embedded package version verification failed: $*" >&2
    echo "Rebuild the native and PHP package payloads from one clean checkout." >&2
    exit 1
}

if [[ ! -d "${PHP_CODE_DIR}" ]]; then
    fail "PHP package payload directory is absent: ${PHP_CODE_DIR}"
fi

mapfile -t PHP_VERSION_FILES < <(find "${PHP_CODE_DIR}" -type f -name 'PhpPartVersion.php' -print | sort)
if [[ "${#PHP_VERSION_FILES[@]}" -eq 0 ]]; then
    fail "no PhpPartVersion.php files found under ${PHP_CODE_DIR}"
fi

NATIVE_VERSION_FILE="${NATIVE_BUILD_DIR}/${BUILD_ARCHITECTURE}-release/libcommon/code/generated/otel_distro_version.h"
if [[ ! -f "${NATIVE_VERSION_FILE}" ]]; then
    fail "native version header is absent: ${NATIVE_VERSION_FILE}"
fi

declare -a PHP_VERSION_VALUES=()
for PHP_VERSION_FILE in "${PHP_VERSION_FILES[@]}"; do
    mapfile -t PHP_VERSION_MATCHES < <(sed -nE "s/^[[:space:]]*public[[:space:]]+const[[:space:]]+VALUE[[:space:]]*=[[:space:]]*'([^']*)';[[:space:]]*$/\1/p" "${PHP_VERSION_FILE}")
    if [[ "${#PHP_VERSION_MATCHES[@]}" -ne 1 ]]; then
        fail "PHP source ${PHP_VERSION_FILE} has an unparseable or ambiguous value: ${PHP_VERSION_MATCHES[*]:-<unparseable>}"
    fi
    PHP_VERSION_VALUE="${PHP_VERSION_MATCHES[0]}"
    echo "PHP source ${PHP_VERSION_FILE}: ${PHP_VERSION_VALUE}"
    if [[ "${PHP_VERSION_VALUE}" == *-dirty* ]]; then
        fail "PHP source ${PHP_VERSION_FILE} has a dirty value: ${PHP_VERSION_VALUE}"
    fi
    PHP_VERSION_VALUES+=("${PHP_VERSION_VALUE}")
done

mapfile -t NATIVE_VERSION_MATCHES < <(sed -nE 's/^[[:space:]]*#define[[:space:]]+OTEL_DISTRO_VERSION[[:space:]]+"([^"]*)"[[:space:]]*$/\1/p' "${NATIVE_VERSION_FILE}")
if [[ "${#NATIVE_VERSION_MATCHES[@]}" -ne 1 ]]; then
    fail "native source ${NATIVE_VERSION_FILE} has an unparseable or ambiguous value: ${NATIVE_VERSION_MATCHES[*]:-<unparseable>}"
fi
NATIVE_VERSION_VALUE="${NATIVE_VERSION_MATCHES[0]}"
echo "Native source ${NATIVE_VERSION_FILE}: ${NATIVE_VERSION_VALUE}"
if [[ "${NATIVE_VERSION_VALUE}" == *-dirty* ]]; then
    fail "native source ${NATIVE_VERSION_FILE} has a dirty value: ${NATIVE_VERSION_VALUE}"
fi

for PHP_VERSION_INDEX in "${!PHP_VERSION_VALUES[@]}"; do
    if [[ "${PHP_VERSION_VALUES[PHP_VERSION_INDEX]}" != "${NATIVE_VERSION_VALUE}" ]]; then
        fail "PHP source ${PHP_VERSION_FILES[PHP_VERSION_INDEX]} has value ${PHP_VERSION_VALUES[PHP_VERSION_INDEX]}, native source ${NATIVE_VERSION_FILE} has value ${NATIVE_VERSION_VALUE}"
    fi
done

for PHP_VERSION_INDEX in "${!PHP_VERSION_VALUES[@]}"; do
    if [[ "${PHP_VERSION_VALUES[PHP_VERSION_INDEX]}" != "${PHP_VERSION_VALUES[0]}" ]]; then
        fail "PHP source ${PHP_VERSION_FILES[PHP_VERSION_INDEX]} has value ${PHP_VERSION_VALUES[PHP_VERSION_INDEX]}, while PHP source ${PHP_VERSION_FILES[0]} has value ${PHP_VERSION_VALUES[0]}"
    fi
done

echo "Embedded package versions match: ${NATIVE_VERSION_VALUE}"
