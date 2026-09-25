# Set up OpenTelemetry PHP Distro

Learn how to instrument your PHP application with OpenTelemetry PHP Distro and send telemetry data to an OTLP-compatible backend.

## Prerequisites

- Have a destination for telemetry data (OTLP endpoint).
- Use a supported Linux and PHP version.
- Do not run another PHP APM or OpenTelemetry agent in the same process.

For supported operating systems and PHP versions, see [Supported technologies](../reference/supported-technologies.md).

## Limitations

Known runtime and compatibility limitations are described in [Limitations](limitations.md).

## Download and install packages

Download a package for your platform from the project releases and install it.

### RPM (RHEL/CentOS/Fedora)

```bash
sudo rpm -ivh <package-file>.rpm
```

### DEB (Debian/Ubuntu)

```bash
sudo dpkg -i <package-file>.deb
```

### APK (Alpine)

```bash
sudo apk add --allow-untrusted <package-file>.apk
```

### OTLP/gRPC installer (Ubuntu 22.04 amd64 only)

OTLP/gRPC is supported through the release installer for Ubuntu 22.04 amd64. The
installer provisions PHP 8.1 CLI and the PECL `grpc` extension before installing
the selected DEB. It is the only supported gRPC provisioning path for this
release; RPM and APK packages do not support OTLP/gRPC.

The installer defaults to release `0.7.0`. Use `--version <version>` to select a
different release. Download the installer and the selected DEB checksum before
running the installer as root:

```bash
VERSION=0.7.0
BASE_URL="https://github.com/open-telemetry/opentelemetry-php-distro/releases/download/v${VERSION}"
DEB="opentelemetry-php-distro_${VERSION}_amd64.deb"

curl -fsSLO "${BASE_URL}/install.sh"
curl -fsSLO "${BASE_URL}/${DEB}"
curl -fsSLO "${BASE_URL}/${DEB}.sha512"
sha512sum --check "${DEB}.sha512"
sudo bash ./install.sh --version "${VERSION}"
```

For the default release, omit `--version 0.7.0`. Do not use this installer on
another Ubuntu release, architecture, or operating system. The installer
provisions the CLI SAPI; provision any additional PHP SAPI separately if your
application requires it.

## Configure exporter

At a minimum, set:

- `OTEL_EXPORTER_OTLP_ENDPOINT`
- `OTEL_EXPORTER_OTLP_HEADERS`

Example:

```bash
export OTEL_EXPORTER_OTLP_ENDPOINT="https://your-otlp-endpoint:443/"
export OTEL_EXPORTER_OTLP_HEADERS="Authorization=Bearer <token>"
```

## Restart PHP processes

After installation and configuration, restart PHP processes (for example `php-fpm`, Apache workers, or long-running CLI workers) so the extension loads.

## Confirm telemetry

1. Open your observability backend.
2. Find your service in traces.
3. Generate traffic if no traces are visible yet.

## Troubleshooting

- Verify configuration options in [Configuration](../reference/configuration.md).
- Check known constraints in [Limitations](limitations.md).
- If using Laravel Octane (Swoole or RoadRunner), see [Long-running PHP servers](../reference/long-running-server.md).
