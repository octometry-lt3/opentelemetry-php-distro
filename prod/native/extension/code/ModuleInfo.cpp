
#include "ConfigurationManager.h"
#include "ConfigurationStorage.h"
#include "ModuleGlobals.h"
#include "VendorCustomizationsInterface.h"

#include <php.h>
#include <ext/standard/info.h>
#include <main/php_version.h>

#include "otel_distro_version.h"

#include <fstream>
#include <string>

namespace opentelemetry::php {

std::string readPhpPartVersion() {
    auto const bootstrapPath = EAPM_CFG(bootstrap_php_part_file);
    auto const phpPartDirectory = bootstrapPath.substr(0, bootstrapPath.find_last_of("/")) + "/" +
        (EAPM_CFG(scoped_deps_enabled) ? "scoped/" + std::to_string(PHP_MAJOR_VERSION) + std::to_string(PHP_MINOR_VERSION) : "not_scoped") +
        "/OpenTelemetry/Distro/PhpPartVersion.php";
    std::ifstream phpPartVersionFile(phpPartDirectory);
    std::string line;
    while (std::getline(phpPartVersionFile, line)) {
        auto const prefix = "    public const VALUE = '";
        auto const start = line.find(prefix);
        if (start != std::string::npos) {
            auto const valueStart = start + std::string(prefix).size();
            auto const valueEnd = line.find("';", valueStart);
            if (valueEnd != std::string::npos) {
                return line.substr(valueStart, valueEnd - valueStart);
            }
        }
    }
    return "";
}

void printPhpInfo(zend_module_entry *zend_module) {

    php_info_print_table_start();
    if (OTEL_G(globals)->vendorCustomizations_) {
        php_info_print_table_header(1, OTEL_G(globals)->vendorCustomizations_->getDistributionName().c_str());
        php_info_print_table_row(2, "Version", OTEL_G(globals)->vendorCustomizations_->getDistributionVersion().c_str());
        php_info_print_table_row(2, "OpenTelemetry distro base version", OTEL_DISTRO_VERSION);

    } else {
        php_info_print_table_header(1, OTEL_DISTRO_PRODUCT_NAME);
        php_info_print_table_row(2, "Version", OTEL_DISTRO_VERSION);
    }
    php_info_print_table_row(2, "Native part version", OTEL_DISTRO_VERSION);
    php_info_print_table_row(2, "PHP part version", readPhpPartVersion().c_str());

    php_info_print_table_colspan_header(2, "Effective configuration");
    php_info_print_table_start();
    php_info_print_table_header(2, "Configuration option", "Value");

    auto const &options = OTEL_G(globals)->configManager_->getOptionMetadata();
    for (auto const &option : options) {
        auto value = opentelemetry::php::ConfigurationManager::accessOptionStringValueByMetadata(option.second, OTEL_GL(config_)->get());
        php_info_print_table_row(2, option.first.c_str(), option.second.secret ? "***" : value.c_str());
    }
    php_info_print_table_end();

    php_info_print_table_colspan_header(2, "INI configuration");
    display_ini_entries(zend_module);
}

} // namespace opentelemetry::php
