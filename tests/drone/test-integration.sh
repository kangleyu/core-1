#!/usr/bin/env bash
set -xeo pipefail

declare -x OC_TEST_ALT_HOME
[[ -z "${OC_TEST_ALT_HOME}" ]] && OC_TEST_ALT_HOME=1

./occ maintenance:install -vvv --database=sqlite --database-name=owncloud --database-table-prefix=oc_ --admin-user=admin --admin-pass=admin --data-dir=$(pwd)/data

pushd tests/integration
    exec ./run.sh $1
popd