# ADR: Installer-owned APT provisioning for PHP 8.1 gRPC

- **Status:** Accepted
- **Date:** 2026-09-28

## Decision

The Ubuntu 22.04 amd64/PHP 8.1 release installer provisions gRPC from the
approved Ondřej Surý Jammy PPA. Ubuntu Jammy's official archives do not supply
`php8.1-grpc`, so the installer adds exactly:

`https://ppa.launchpadcontent.net/ondrej/php/ubuntu jammy main`

It obtains the signing key from the Ubuntu keyserver lookup URL for the
approved key,
`https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C`,
and accepts it only when its fingerprint is
`14AA40EC0831756756D7F66C4F4EA0AAE5267A6C`. The installer stores the
dearmored key at `/usr/share/keyrings/ppa_ondrej_php.gpg` and configures the
source at `/etc/apt/sources.list.d/ppa_ondrej_php.list` with that keyring's
`signed-by` option. It does not use `apt-key` or add the key to global trust.

The release installer owns repository setup, installation of `php8.1-cli` and
`php8.1-grpc`, module enablement, and the PHP 8.1 CLI load check. The PPA
publisher owns the signing key; OpenTelemetry PHP Distro release maintainers
own monitoring the key, coordinating rotation or revocation with the
publisher, and updating the installer and documentation when required.

The Distro DEB remains independent of this repository and must not install
APT sources or declare `php8.1-grpc`. gRPC is opt-in and supported only through
this installer boundary: Ubuntu 22.04 amd64 with PHP 8.1 CLI. The packaged,
scoped Composer dependency `open-telemetry/transport-grpc` remains required
for transport/provider support; it does not replace the PHP extension.

## Reconciled alternatives

This decision supersedes the earlier PECL-based installer decision and plan:
the installer no longer compiles gRPC or installs PECL build prerequisites.
It also rejects making the DEB depend on `php8.1-grpc`, because that would
couple ordinary DEB installation to an external repository and narrow normal
package support. RPM and APK packages, other Ubuntu releases and
architectures, and other PHP minors remain outside this contract.

## Consequences

Users of the supported gRPC path must run the release installer, which makes a
controlled external repository trust decision. The installer must fail when
the host is outside Ubuntu 22.04 amd64 or when the key fingerprint does not
match, and must verify the selected DEB checksum before installing it. This is
an unreleased contract; the repository's current `0.7.0` version is unchanged.
