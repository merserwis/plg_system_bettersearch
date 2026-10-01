#!/bin/bash
# Build pkg_bettersearch-<version>.zip (plugin + module + admin menu component). Usage: ./build.sh
set -e
cd "$(dirname "$0")"
V=$(grep -oP '(?<=<version>)[^<]+' pkg_bettersearch.xml)
rm -rf build && mkdir -p build/packages
(cd plg_system_bettersearch && zip -qrX ../build/packages/plg_system_bettersearch.zip .)
(cd com_bettersearch && zip -qrX ../build/packages/com_bettersearch.zip .)
(cd mod_bettersearch && zip -qrX ../build/packages/mod_bettersearch.zip .)
cp pkg_bettersearch.xml build/
rm -f "pkg_bettersearch-$V.zip"
(cd build && zip -qrX "../pkg_bettersearch-$V.zip" pkg_bettersearch.xml packages)
echo "pkg_bettersearch-$V.zip"
