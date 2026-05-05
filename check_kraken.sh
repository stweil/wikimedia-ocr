#!/usr/bin/env bash

set -euo pipefail

# Note, this assumes that the output of `kraken --version` will remain consistent.
MIN_KRAKEN_VERSION="kraken 4"

if [ -n "${DISABLE_KRAKEN_CHECK+placeholder}" ]; then
  echo "DISABLE_KRAKEN_CHECK is set, skipping kraken check."
  exit 0
fi

echo "Checking kraken installation"

if ! type kraken &> /dev/null; then
  echo "Kraken not found!"
  exit 1
else
  echo "Kraken executable OK"
fi

# Similar to what kraken-ocr-for-php does
CUR_KRAKEN_VERSION=$(kraken --version | head -n1 | sed "s/kraken v/kraken /")
CUR_MIN_VERSION=$( echo -e "$MIN_KRAKEN_VERSION\n$CUR_KRAKEN_VERSION" | sort -V | head -n1 )
if [ "$CUR_MIN_VERSION" != "$MIN_KRAKEN_VERSION" ]; then
  echo "Kraken version mismatch: current is ${CUR_KRAKEN_VERSION}, minimum required is ${MIN_KRAKEN_VERSION}"
  exit 1
else
  echo "Kraken version OK"
fi

# For the future, we might make languages optional; we'd probably have to cache the result of `kraken --list-langs`.

if type jq &> /dev/null; then
  # Sort both just in case, and remove duplicates from the expected list to account for google having more variants that
  # map to the same code in kraken (e.g. zh and zh-hans)
  # Skip deu_latf as it's not insalled by default yet (but will be in the future).
  AVAILABLE_LANGS=$(kraken --list-langs | tail -n +2 | sort)
  EXPECTED_LANGS=$(jq -r '.kraken | keys | to_entries[] | .value' public/models.json | sort -u | sed "/^deu_latf$/d" )

  EXTRA_LOCAL_LANGS=$( comm -23 <( echo "$AVAILABLE_LANGS" ) <( echo "$EXPECTED_LANGS" ) )
  MISSING_LOCAL_LANGS=$( comm -13 <( echo "$AVAILABLE_LANGS" ) <( echo "$EXPECTED_LANGS" ) )

  if [ -z "$MISSING_LOCAL_LANGS" ]; then
    echo "All expected languages are installed"
  else
    echo -e "The following required languages are not installed:\n$MISSING_LOCAL_LANGS"
    exit 1
  fi
  if [ -n "$EXTRA_LOCAL_LANGS" ]; then
    echo -e "The following languages are installed but not supported:\n$EXTRA_LOCAL_LANGS"
  fi
else
  echo "jq is not installed, skipping validation of available languages"
fi

echo "All checks passed!"
