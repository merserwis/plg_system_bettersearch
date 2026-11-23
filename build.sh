#!/bin/bash
# Build pkg_bettersearch-<version>.zip (plugin + module + admin menu component). Usage: ./build.sh
set -e
cd "$(dirname "$0")"
# the shared reader of technical parameters (~/dev/gb-shared) must be the current copy
if [ -d ../../gb-shared ]; then
    python3 ../../gb-shared/tools/sync.py src/Params.php plg_system_bettersearch/src/Engine/Params.php 'Merserwis\Plugin\System\BetterSearch\Engine' --check
fi
V=$(grep -oP '(?<=<version>)[^<]+' pkg_bettersearch.xml)
rm -rf build && mkdir -p build/packages
(cd plg_system_bettersearch && zip -qrX ../build/packages/plg_system_bettersearch.zip .)
(cd com_bettersearch && zip -qrX ../build/packages/com_bettersearch.zip .)
(cd mod_bettersearch && zip -qrX ../build/packages/mod_bettersearch.zip .)
cp pkg_bettersearch.xml build/
rm -f "pkg_bettersearch-$V.zip"
(cd build && zip -qrX "../pkg_bettersearch-$V.zip" pkg_bettersearch.xml packages)
echo "pkg_bettersearch-$V.zip"
